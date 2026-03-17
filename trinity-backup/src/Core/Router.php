<?php
/**
 * AJAX request router.
 *
 * @package TrinityBackup
 */

declare( strict_types=1 );

namespace TrinityBackup\Core;

defined( 'ABSPATH' ) || exit;

use TrinityBackup\Engine\ImportPipeline;
use TrinityBackup\Engine\Pipeline;
use TrinityBackup\Archiver\TrinityCompressor;

final class Router
{
    private Pipeline $exportPipeline;
    private ImportPipeline $importPipeline;

    public function __construct(Pipeline $exportPipeline, ImportPipeline $importPipeline)
    {
        $this->exportPipeline = $exportPipeline;
        $this->importPipeline = $importPipeline;
    }

    public function register(): void
    {
        // Export
        add_action('wp_ajax_trinity_backup_start', [$this, 'handleStart']);
        add_action('wp_ajax_trinity_backup_run', [$this, 'handleRun']);
        
        // Import
        add_action('wp_ajax_trinity_backup_upload', [$this, 'handleUpload']);
        add_action('wp_ajax_trinity_backup_upload_chunk', [$this, 'handleUploadChunk']);
        add_action('wp_ajax_trinity_backup_check_archive', [$this, 'handleCheckArchive']);
        add_action('wp_ajax_trinity_backup_import_start', [$this, 'handleImportStart']);
        add_action('wp_ajax_trinity_backup_import_run', [$this, 'handleImportRun']);
        
        // Backups management
        add_action('wp_ajax_trinity_backup_list_backups', [$this, 'handleListBackups']);
        add_action('wp_ajax_trinity_backup_delete', [$this, 'handleDeleteBackup']);
        add_action('wp_ajax_trinity_backup_delete_all', [$this, 'handleDeleteAllBackups']);
        add_action('wp_ajax_trinity_backup_cleanup', [$this, 'handleCleanup']);
        
        // Compatibility check
        add_action('wp_ajax_trinity_backup_check', [$this, 'handleCompatibilityCheck']);
        
        // Settings
        add_action('wp_ajax_trinity_backup_schedule', [$this, 'handleSchedule']);
        add_action('wp_ajax_trinity_backup_get_settings', [$this, 'handleGetSettings']);
        add_action('wp_ajax_trinity_backup_preupdate_save', [$this, 'handleSavePreUpdateSettings']);
        add_action('wp_ajax_trinity_backup_email_save', [$this, 'handleSaveEmailSettings']);
        add_action('wp_ajax_trinity_backup_email_test', [$this, 'handleSendTestEmail']);
        add_action('wp_ajax_trinity_backup_whitelabel_save', [$this, 'handleSaveWhiteLabelSettings']);
        
        // User preferences
        add_action('wp_ajax_trinity_backup_save_theme', [$this, 'handleSaveTheme']);
    }

    public function handleSendTestEmail(): void
    {
        $this->assertNonce();
        $this->assertCapability();
        $this->assertProFeature('email');

        $settings = class_exists(EmailNotifier::class) ? EmailNotifier::getSettings() : [];
        $recipientsRaw = (string) ($settings['recipients'] ?? '');
        $recipients = array_filter(array_map('trim', explode(',', $recipientsRaw)));
        if (empty($recipients)) {
            $recipients = [(string) get_option('admin_email')];
        }

        $brandName = class_exists(WhiteLabel::class) ? WhiteLabel::getPluginName() : 'Trinity Backup';
        $siteName = (string) get_bloginfo('name');
        $siteName = $siteName !== '' ? $siteName : (string) (wp_parse_url(home_url(), PHP_URL_HOST) ?? 'WordPress');

        $subject = sprintf('[%s] %s test email', $siteName, $brandName);
        $body = '<p>This is a test email from <strong>' . esc_html($brandName) . '</strong>.</p>'
            . '<p>Site: ' . esc_html(home_url()) . '</p>'
            . '<p>Time: ' . esc_html(date_i18n('Y-m-d H:i:s')) . '</p>';

        $headers = ['Content-Type: text/html; charset=UTF-8'];

        $mailError = '';
        $listener = static function ($wpError) use (&$mailError): void {
            if (is_object($wpError) && method_exists($wpError, 'get_error_message')) {
                $mailError = (string) $wpError->get_error_message();
            }
        };

        add_action('wp_mail_failed', $listener, 10, 1);
        try {
            $ok = wp_mail($recipients, $subject, $body, $headers);
        } finally {
            remove_action('wp_mail_failed', $listener, 10);
        }

        if (!$ok) {
            $msg = $mailError !== ''
                ? 'Mail failed: ' . $mailError
                : 'Mail failed. wp_mail() returned false.';
            wp_send_json_error(['message' => $msg]);
        }

        wp_send_json([
            'status' => 'ok',
            'message' => 'Test email sent.',
            'recipients' => $recipients,
        ]);
    }

    public function handleStart(): void
    {
        $this->assertNonce();
        $this->assertCapability();

        $lock = new OperationLock();
        $acquired = $lock->acquire('manual_backup', $this->getLockOwner(), HOUR_IN_SECONDS);
        if (!($acquired['ok'] ?? false) || empty($acquired['token'])) {
            wp_send_json_error(['message' => $acquired['message'] ?? 'Another operation is currently running.'], 409);
        }

        $token = (string) $acquired['token'];
        
        try {
            // Get export options
            $options = $this->getExportOptions();
            $options['origin'] = 'manual';
            $options['operation_lock_token'] = $token;
            $response = $this->exportPipeline->start($options);
            wp_send_json($response);
        } catch (\Throwable $throwable) {
            $lock->release($token);
            wp_send_json_error(['message' => $throwable->getMessage()]);
        }
    }

    public function handleRun(): void
    {
        $this->assertNonce();
        $this->assertCapability();

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in assertNonce()
        $jobId = isset($_POST['job_id']) ? sanitize_text_field(wp_unslash((string) $_POST['job_id'])) : '';
        if ($jobId === '') {
            wp_send_json_error(['message' => 'Missing job id.']);
        }

        $lock = new OperationLock();
        $stateManager = new StateManager();
        $state = $stateManager->load($jobId);
        $token = '';
        if (is_array($state)) {
            $token = (string) (($state['options']['operation_lock_token'] ?? '') ?: ($state['operation_lock_token'] ?? ''));
        }

        if ($token !== '') {
            $lock->touch($token, HOUR_IN_SECONDS);
        } else {
            // Fallback: ensure no conflicting operation is running.
            $blocked = $lock->isBlocked('manual_backup');
            if (is_array($blocked)) {
                wp_send_json_error(['message' => 'Cannot continue export while another operation is running.'], 409);
            }
        }

        try {
            $response = $this->exportPipeline->run($jobId);

            $status = (string) ($response['status'] ?? '');
            if ($status === 'done' || $status === 'error') {
                $stateAfter = $stateManager->load($jobId);
                $notifyKey = 'export_' . $status;
                $alreadyNotified = is_array($stateAfter)
                    && isset($stateAfter['email_notified'])
                    && is_array($stateAfter['email_notified'])
                    && !empty($stateAfter['email_notified'][$notifyKey]);

                if (!$alreadyNotified
                    && function_exists('trinity_backup_has_feature')
                    && trinity_backup_has_feature('email')
                    && class_exists(EmailNotifier::class)) {
                    if ($status === 'done') {
                        EmailNotifier::notifyExportSuccess($response['stats'] ?? [], 'manual');
                    } else {
                        $message = (string) ($response['message'] ?? 'Unknown error');
                        EmailNotifier::notifyExportFailure($message, 'manual');
                    }

                    if (is_array($stateAfter)) {
                        if (!isset($stateAfter['email_notified']) || !is_array($stateAfter['email_notified'])) {
                            $stateAfter['email_notified'] = [];
                        }
                        $stateAfter['email_notified'][$notifyKey] = 1;
                        $stateManager->save($jobId, $stateAfter);
                    }
                }
            }

            if ($status === 'done' || $status === 'error') {
                if ($token !== '') {
                    $lock->release($token);
                }
            }
            wp_send_json($response);
        } catch (\Throwable $throwable) {
            $stateAfter = $stateManager->load($jobId);
            $notifyKey = 'export_exception';
            $alreadyNotified = is_array($stateAfter)
                && isset($stateAfter['email_notified'])
                && is_array($stateAfter['email_notified'])
                && !empty($stateAfter['email_notified'][$notifyKey]);

            if (!$alreadyNotified
                && function_exists('trinity_backup_has_feature')
                && trinity_backup_has_feature('email')
                && class_exists(EmailNotifier::class)) {
                EmailNotifier::notifyExportFailure($throwable->getMessage(), 'manual');

                if (is_array($stateAfter)) {
                    if (!isset($stateAfter['email_notified']) || !is_array($stateAfter['email_notified'])) {
                        $stateAfter['email_notified'] = [];
                    }
                    $stateAfter['email_notified'][$notifyKey] = 1;
                    $stateManager->save($jobId, $stateAfter);
                }
            }

            if ($token !== '') {
                $lock->release($token);
            }
            wp_send_json_error(['message' => $throwable->getMessage()]);
        }
    }

    public function handleUpload(): void
    {
        $this->assertNonce();
        $this->assertCapability();

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing -- $_FILES is handled by wp_handle_upload, nonce verified in assertNonce()
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in assertNonce()
        if (empty($_FILES['file'])) {
            wp_send_json_error(['message' => 'Missing file.']);
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- $_FILES is handled by wp_handle_upload, nonce verified in assertNonce()
        $file = $_FILES['file'];
        if (!isset($file['name'])) {
            wp_send_json_error(['message' => 'Invalid file upload.']);
        }

        $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        if ($extension !== 'trinity') {
            wp_send_json_error(['message' => 'Only .trinity archives are supported.']);
        }

        if (!function_exists('wp_handle_upload')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $uploaded = wp_handle_upload($file, ['test_form' => false]);
        if (!is_array($uploaded) || isset($uploaded['error'])) {
            wp_send_json_error(['message' => $uploaded['error'] ?? 'Upload failed.']);
        }

        wp_send_json([
            'status' => 'ok',
            'path' => $uploaded['file'],
            'url' => $uploaded['url'],
        ]);
    }

    /**
     * Handle chunked file upload for large files.
     */
    public function handleUploadChunk(): void
    {
        $this->assertNonce();
        $this->assertCapability();

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing -- $_FILES handled manually for chunked upload, nonce verified in assertNonce()
        if (empty($_FILES['chunk'])) {
            wp_send_json_error(['message' => 'Missing chunk data.']);
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in assertNonce()
        $filename = isset($_POST['filename']) ? sanitize_file_name(wp_unslash((string) $_POST['filename'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in assertNonce()
        $chunkIndex = isset($_POST['chunk_index']) ? (int) $_POST['chunk_index'] : 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in assertNonce()
        $totalChunks = isset($_POST['total_chunks']) ? (int) $_POST['total_chunks'] : 1;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in assertNonce()
        $uploadId = isset($_POST['upload_id']) ? sanitize_file_name(wp_unslash((string) $_POST['upload_id'])) : '';

        if ($filename === '' || $uploadId === '') {
            wp_send_json_error(['message' => 'Missing filename or upload ID.']);
        }

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($extension !== 'trinity') {
            wp_send_json_error(['message' => 'Only .trinity archives are supported.']);
        }

        // Create upload directory
        $uploads = wp_upload_dir();
        $uploadDir = trailingslashit($uploads['basedir']) . 'trinity-backup/uploads/' . $uploadId;
        
        if (!is_dir($uploadDir)) {
            wp_mkdir_p($uploadDir);
        }

        // Save chunk
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.InputNotValidated,WordPress.Security.NonceVerification.Missing -- tmp_name is validated by PHP upload mechanism, nonce verified in assertNonce()
        $chunkFile = isset($_FILES['chunk']['tmp_name']) ? $_FILES['chunk']['tmp_name'] : '';
        if ($chunkFile === '' || !is_uploaded_file($chunkFile)) {
            wp_send_json_error(['message' => 'Invalid chunk upload.']);
        }
        $chunkPath = $uploadDir . '/chunk_' . str_pad((string) $chunkIndex, 5, '0', STR_PAD_LEFT);
        
        // Use WordPress filesystem API to move the uploaded chunk
        // First read the uploaded file content, then write to destination
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading uploaded temp file
        $chunkContent = file_get_contents($chunkFile);
        if ($chunkContent === false) {
            wp_send_json_error(['message' => 'Failed to read uploaded chunk.']);
        }
        
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing binary chunk data
        $written = file_put_contents($chunkPath, $chunkContent);
        if ($written === false) {
            wp_send_json_error(['message' => 'Failed to save chunk.']);
        }
        
        // Clean up temp file
        wp_delete_file($chunkFile);

        // Check if all chunks uploaded
        $uploadedChunks = glob($uploadDir . '/chunk_*');
        $uploadedCount = is_array($uploadedChunks) ? count($uploadedChunks) : 0;

        if ($uploadedCount < $totalChunks) {
            wp_send_json([
                'status' => 'uploading',
                'chunks_uploaded' => $uploadedCount,
                'total_chunks' => $totalChunks,
                'progress' => (int) (($uploadedCount / $totalChunks) * 100),
            ]);
            return;
        }

        // All chunks uploaded - combine them into a folder
        $stateManager = new StateManager();
        $backupId = $stateManager->generateBackupName();
        $jobDir = trailingslashit($uploads['basedir']) . 'trinity-backup/' . $backupId;
        
        if (!is_dir($jobDir)) {
            wp_mkdir_p($jobDir);
        }
        
        $finalPath = $jobDir . '/' . $backupId . '.trinity';

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming binary assembly requires direct file handle
        $finalHandle = fopen($finalPath, 'wb');
        if ($finalHandle === false) {
            wp_send_json_error(['message' => 'Failed to create final file.']);
        }

        // Sort and combine chunks
        sort($uploadedChunks);
        foreach ($uploadedChunks as $chunk) {
            $chunkData = file_get_contents($chunk);
            if ($chunkData !== false) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Streaming binary write
                fwrite($finalHandle, $chunkData);
            }
            wp_delete_file($chunk);
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing stream handle
        fclose($finalHandle);

        // Cleanup chunk directory
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Cleanup empty temp directory
        @rmdir($uploadDir);

        wp_send_json([
            'status' => 'ok',
            'path' => $finalPath,
            'url' => trailingslashit($uploads['baseurl']) . 'trinity-backup/' . $backupId . '/' . $backupId . '.trinity',
        ]);
    }

    /**
     * Check if archive is encrypted and optionally verify password.
     */
    public function handleCheckArchive(): void
    {
        $this->assertNonce();
        $this->assertCapability();

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in assertNonce()
        $archivePath = isset($_POST['archive_path']) ? sanitize_text_field(wp_unslash((string) $_POST['archive_path'])) : '';
        if ($archivePath === '') {
            wp_send_json_error(['message' => 'Missing archive path.']);
        }

        if (!$this->isAllowedArchive($archivePath)) {
            wp_send_json_error(['message' => 'Archive path not allowed.']);
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in assertNonce()
        $password = isset($_POST['password']) ? sanitize_text_field(wp_unslash((string) $_POST['password'])) : '';

        try {
            $extractor = new \TrinityBackup\Archiver\TrinityExtractor($archivePath);
            $extractor->open();
            
            // Check if archive is encrypted by looking for the signature entry.
            // We cannot validate signature *content* without a password because encrypted
            // file contents won't decrypt. Presence of the entry is the marker; content
            // validation happens after a password is provided.
            $isEncrypted = false;
            foreach ($extractor->listFiles() as $file) {
                $relativePath = $file['path'] !== '' ? $file['path'] . '/' . $file['name'] : $file['name'];
                if ($relativePath === '_encryption_signature') {
                    $isEncrypted = true;
                    break;
                }
            }
            
            if (!$isEncrypted) {
                // Archive is not encrypted
                $extractor->close();
                wp_send_json([
                    'status' => 'ok',
                    'encrypted' => false,
                ]);
                return;
            }
            
            // Archive is encrypted
            if ($password === '') {
                // No password provided - tell client archive needs password
                $extractor->close();
                wp_send_json([
                    'status' => 'ok',
                    'encrypted' => true,
                    'password_required' => true,
                ]);
                return;
            }
            
            // Password provided - verify it
            $extractor->setPassword($password);
            
            try {
                $signatureContent = $extractor->getFileContent('_encryption_signature');
            } catch (\Throwable $decryptError) {
                $message = $decryptError->getMessage();
                // Distinguish server capability errors from bad password/corruption.
                $extractor->close();
                if (str_contains($message, 'Server does not support AES-256-GCM')) {
                    wp_send_json_error(['message' => $message]);
                    return;
                }

                wp_send_json([
                    'status' => 'ok',
                    'encrypted' => true,
                    'password_valid' => false,
                    'message' => 'Wrong password or corrupted archive.',
                ]);
                return;
            }
            
            $extractor->close();
            
            $expectedSignature = \TrinityBackup\Engine\Steps\ExportFiles::ENCRYPTION_SIGNATURE;
            
            if ($signatureContent !== $expectedSignature) {
                wp_send_json([
                    'status' => 'ok',
                    'encrypted' => true,
                    'password_valid' => false,
                    'message' => 'Incorrect password.',
                ]);
                return;
            }
            
            // Password is correct
            wp_send_json([
                'status' => 'ok',
                'encrypted' => true,
                'password_valid' => true,
            ]);
            
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public function handleImportStart(): void
    {
        $this->assertNonce();
        $this->assertCapability();

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in assertNonce()
        $archivePath = isset($_POST['archive_path']) ? sanitize_text_field(wp_unslash((string) $_POST['archive_path'])) : '';
        if ($archivePath === '') {
            wp_send_json_error(['message' => 'Missing archive path.']);
        }

        if (!$this->isAllowedArchive($archivePath)) {
            wp_send_json_error(['message' => 'Archive path not allowed.']);
        }

        // Get import options (URL replacement, password)
        $options = [];
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in assertNonce()
        if (!empty($_POST['old_url'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in assertNonce()
            $options['old_url'] = esc_url_raw(wp_unslash((string) $_POST['old_url']));
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in assertNonce()
        if (!empty($_POST['new_url'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in assertNonce()
            $options['new_url'] = esc_url_raw(wp_unslash((string) $_POST['new_url']));
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in assertNonce()
        if (!empty($_POST['password'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in assertNonce()
            $options['password'] = sanitize_text_field(wp_unslash((string) $_POST['password']));
        }

        $lock = new OperationLock();
        $acquired = $lock->acquire('restore', $this->getLockOwner(), 2 * HOUR_IN_SECONDS);
        if (!($acquired['ok'] ?? false) || empty($acquired['token'])) {
            wp_send_json_error(['message' => $acquired['message'] ?? 'Another operation is currently running.'], 409);
        }

        $token = (string) $acquired['token'];
        $options['operation_lock_token'] = $token;

        try {
            $response = $this->importPipeline->start($archivePath, $options);
            wp_send_json($response);
        } catch (\Throwable $throwable) {
            $lock->release($token);
            wp_send_json_error(['message' => $throwable->getMessage()]);
        }
    }

    public function handleImportRun(): void
    {
        $this->assertNonce();
        $this->assertCapability();

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in assertNonce()
        $jobId = isset($_POST['job_id']) ? sanitize_text_field(wp_unslash((string) $_POST['job_id'])) : '';
        if ($jobId === '') {
            wp_send_json_error(['message' => 'Missing job id.']);
        }

        $lock = new OperationLock();
        $stateManager = new StateManager();
        $state = $stateManager->load($jobId);
        if ($state === null) {
            $fallback = $stateManager->loadCurrent();
            if (is_array($fallback) && ($fallback['job_type'] ?? '') === 'import') {
                $state = $fallback;
            }
        }
        $token = '';
        if (is_array($state)) {
            $token = (string) (($state['options']['operation_lock_token'] ?? '') ?: ($state['operation_lock_token'] ?? ''));
        }

        if ($token !== '') {
            $lock->touch($token, 2 * HOUR_IN_SECONDS);
        } else {
            $blocked = $lock->isBlocked('restore');
            if (is_array($blocked)) {
                wp_send_json_error(['message' => 'Cannot continue restore while another operation is running.'], 409);
            }
        }

        try {
            $response = $this->importPipeline->run($jobId);

            $status = (string) ($response['status'] ?? '');
            if ($status === 'done' || $status === 'error') {
                $stateAfter = $stateManager->load($jobId);
                $notifyKey = 'import_' . $status;
                $alreadyNotified = is_array($stateAfter)
                    && isset($stateAfter['email_notified'])
                    && is_array($stateAfter['email_notified'])
                    && !empty($stateAfter['email_notified'][$notifyKey]);

                if (!$alreadyNotified
                    && function_exists('trinity_backup_has_feature')
                    && trinity_backup_has_feature('email')
                    && class_exists(EmailNotifier::class)) {
                    if ($status === 'done') {
                        EmailNotifier::notifyImportSuccess($response['stats'] ?? []);
                    } else {
                        $message = (string) ($response['message'] ?? 'Unknown error');
                        EmailNotifier::notifyImportFailure($message);
                    }

                    if (is_array($stateAfter)) {
                        if (!isset($stateAfter['email_notified']) || !is_array($stateAfter['email_notified'])) {
                            $stateAfter['email_notified'] = [];
                        }
                        $stateAfter['email_notified'][$notifyKey] = 1;
                        $stateManager->save($jobId, $stateAfter);
                    }
                }
            }

            if ($status === 'done' || $status === 'error') {
                if ($token !== '') {
                    $lock->release($token);
                }
            }
            wp_send_json($response);
        } catch (\Throwable $throwable) {
            $stateAfter = $stateManager->load($jobId);
            $notifyKey = 'import_exception';
            $alreadyNotified = is_array($stateAfter)
                && isset($stateAfter['email_notified'])
                && is_array($stateAfter['email_notified'])
                && !empty($stateAfter['email_notified'][$notifyKey]);

            if (!$alreadyNotified
                && function_exists('trinity_backup_has_feature')
                && trinity_backup_has_feature('email')
                && class_exists(EmailNotifier::class)) {
                EmailNotifier::notifyImportFailure($throwable->getMessage());

                if (is_array($stateAfter)) {
                    if (!isset($stateAfter['email_notified']) || !is_array($stateAfter['email_notified'])) {
                        $stateAfter['email_notified'] = [];
                    }
                    $stateAfter['email_notified'][$notifyKey] = 1;
                    $stateManager->save($jobId, $stateAfter);
                }
            }

            if ($token !== '') {
                $lock->release($token);
            }
            wp_send_json_error(['message' => $throwable->getMessage()]);
        }
    }

    public function handleListBackups(): void
    {
        $this->assertNonce();
        $this->assertCapability();

        try {
            $manager = new BackupManager();
            $backups = $manager->listBackups();

            $dateFormat = (string) get_option('date_format');
            $timeFormat = (string) get_option('time_format');
            $dateTimeFormat = trim($dateFormat . ' ' . $timeFormat);
            if ($dateTimeFormat === '') {
                $dateTimeFormat = 'Y-m-d H:i';
            }
            
            // Format for JavaScript
            $formatted = array_map(static function ($backup) use ($dateTimeFormat) {
                $createdTs = (int) ($backup['created'] ?? 0);
                return [
                    'id' => $backup['id'],
                    'filename' => $backup['filename'],
                    'size' => $backup['size'],
                    'created' => $createdTs,
                    'created_formatted' => $createdTs > 0 ? date_i18n($dateTimeFormat, $createdTs) : '',
                    'origin' => $backup['origin'] ?? 'manual',
                    'url' => $backup['url'],
                    'path' => $backup['path'],
                ];
            }, $backups);

            wp_send_json([
                'success' => true,
                'backups' => $formatted,
                'total_size' => $manager->getTotalSize(),
            ]);
        } catch (\Throwable $throwable) {
            wp_send_json_error(['message' => $throwable->getMessage()]);
        }
    }

    public function handleDeleteBackup(): void
    {
        $this->assertNonce();
        $this->assertCapability();

        // Prefer deleting by backup id; keep filename for backwards compatibility.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in assertNonce()
        $id = isset($_POST['id']) ? sanitize_file_name(wp_unslash((string) $_POST['id'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in assertNonce()
        $filename = isset($_POST['filename']) ? sanitize_file_name(wp_unslash((string) $_POST['filename'])) : '';
        if ($id === '' && $filename === '') {
            wp_send_json_error(['message' => 'Missing backup id.']);
        }

        $lock = new OperationLock();
        $acquired = $lock->acquire('delete_backup', $this->getLockOwner(), 10 * MINUTE_IN_SECONDS);
        if (!($acquired['ok'] ?? false) || empty($acquired['token'])) {
            wp_send_json_error(['message' => $acquired['message'] ?? 'Another operation is currently running.'], 409);
        }

        $token = (string) $acquired['token'];

        try {
            $manager = new BackupManager();
            $deleted = $manager->deleteBackup($id !== '' ? $id : $filename);
            
            if ($deleted) {
                $lock->release($token);
                wp_send_json(['success' => true, 'message' => 'Backup deleted.']);
            } else {
                $lock->release($token);
                wp_send_json_error(['message' => 'Backup not found.']);
            }
        } catch (\Throwable $throwable) {
            $lock->release($token);
            wp_send_json_error(['message' => $throwable->getMessage()]);
        }
    }

    public function handleCleanup(): void
    {
        $this->assertNonce();
        $this->assertCapability();

        $lock = new OperationLock();
        $acquired = $lock->acquire('cleanup', $this->getLockOwner(), 5 * MINUTE_IN_SECONDS);
        if (!($acquired['ok'] ?? false) || empty($acquired['token'])) {
            wp_send_json_error(['message' => $acquired['message'] ?? 'Another operation is currently running.'], 409);
        }

        $token = (string) $acquired['token'];

        $payload = null;
        $error = null;

        try {
            $manager = new BackupManager();
            $cleaned = $manager->cleanupOldJobs(24);

            $payload = [
                'success' => true,
                'cleaned' => $cleaned,
                'message' => sprintf('Cleaned up %d incomplete jobs.', $cleaned),
            ];
        } catch (\Throwable $throwable) {
            $error = $throwable->getMessage();
        }

        $lock->release($token);

        if ($error !== null) {
            wp_send_json_error(['message' => $error]);
        }

        wp_send_json($payload);
    }

    public function handleDeleteAllBackups(): void
    {
        $this->assertNonce();
        $this->assertCapability();

        $lock = new OperationLock();
        $acquired = $lock->acquire('delete_all', $this->getLockOwner(), 30 * MINUTE_IN_SECONDS);
        if (!($acquired['ok'] ?? false) || empty($acquired['token'])) {
            wp_send_json_error(['message' => $acquired['message'] ?? 'Another operation is currently running.'], 409);
        }

        $token = (string) $acquired['token'];

        $payload = null;
        $error = null;

        try {
            $manager = new BackupManager();
            $backups = $manager->listBackups();
            $deleted = 0;

            foreach ($backups as $backup) {
                if ($manager->deleteBackup($backup['id'])) {
                    $deleted++;
                }
            }

            $payload = [
                'success' => true,
                'deleted' => $deleted,
                'message' => sprintf('Deleted %d backups.', $deleted),
            ];
        } catch (\Throwable $throwable) {
            $error = $throwable->getMessage();
        }

        $lock->release($token);

        if ($error !== null) {
            wp_send_json_error(['message' => $error]);
        }

        wp_send_json($payload);
    }

    public function handleCompatibilityCheck(): void
    {
        $this->assertNonce();
        $this->assertCapability();

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in assertNonce()
        $archivePath = isset($_POST['archive_path']) ? sanitize_text_field(wp_unslash((string) $_POST['archive_path'])) : '';

        try {
            $checker = new CompatibilityChecker();
            $results = $checker->runAll($archivePath);
            
            wp_send_json($results);
        } catch (\Throwable $throwable) {
            wp_send_json_error(['message' => $throwable->getMessage()]);
        }
    }

    public function handleSchedule(): void
    {
        $this->assertNonce();
        $this->assertCapability();
        $this->assertProFeature('scheduled');

        if (!class_exists(Scheduler::class)) {
            wp_send_json_error(['message' => 'Scheduled backups module is not available. Activate Trinity Backup Pro.'], 503);
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in assertNonce()
        $frequency = isset($_POST['frequency']) ? sanitize_text_field(wp_unslash((string) $_POST['frequency'])) : 'disabled';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in assertNonce()
        $time = isset($_POST['time']) ? sanitize_text_field(wp_unslash((string) $_POST['time'])) : '03:00';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in assertNonce()
        $retention = isset($_POST['retention']) ? (int) $_POST['retention'] : 5;

        try {
            // Update retention
            update_option(Scheduler::OPTION_RETENTION, max(1, min(30, $retention)));
            
            // Schedule
            $result = Scheduler::schedule($frequency, $time);
            
            if ($result) {
                wp_send_json([
                    'status' => 'ok',
                    'message' => 'Schedule updated.',
                    'schedule' => Scheduler::getScheduleInfo(),
                ]);
            } else {
                wp_send_json_error(['message' => 'Failed to update schedule.']);
            }
        } catch (\Throwable $throwable) {
            wp_send_json_error(['message' => $throwable->getMessage()]);
        }
    }

    public function handleGetSettings(): void
    {
        $this->assertNonce();
        $this->assertCapability();

        try {
            $lock = new OperationLock();
            $isPro = function_exists('trinity_backup_can_use_pro') && trinity_backup_can_use_pro();

            $data = [
                'status' => 'ok',
                'schedule' => class_exists(Scheduler::class) ? Scheduler::getScheduleInfo() : ['frequency' => 'disabled', 'time' => '03:00', 'retention' => 5, 'next_run' => 0],
                'preupdate' => class_exists(PreUpdateBackups::class) ? PreUpdateBackups::getSettings() : ['enabled' => false, 'block_updates' => true, 'no_media' => false, 'no_plugins' => false, 'no_themes' => false, 'no_database' => false, 'no_spam_comments' => false, 'no_email_replace' => false],
                'email' => class_exists(EmailNotifier::class) ? EmailNotifier::getSettings() : ['enabled' => false, 'recipients' => '', 'on_manual' => false, 'on_scheduled' => true, 'on_pre_update' => true, 'on_import' => true, 'on_failure_only' => false],
                'whitelabel' => class_exists(WhiteLabel::class) ? WhiteLabel::getSettings() : ['enabled' => false, 'plugin_name' => '', 'plugin_description' => '', 'author_name' => '', 'author_url' => '', 'menu_icon' => '', 'hide_branding' => false],
                'encryption_supported' => $this->isTrinityEncryptionSupported(),
                'operation_lock' => $lock->getActive(),
                'is_pro' => $isPro,
                'pro_features' => [
                    'scheduled' => function_exists('trinity_backup_has_feature') && trinity_backup_has_feature('scheduled'),
                    'pre_update' => function_exists('trinity_backup_has_feature') && trinity_backup_has_feature('pre_update'),
                    'encryption' => function_exists('trinity_backup_has_feature') && trinity_backup_has_feature('encryption'),
                    'email' => function_exists('trinity_backup_has_feature') && trinity_backup_has_feature('email'),
                    'wp_cli' => function_exists('trinity_backup_has_feature') && trinity_backup_has_feature('wp_cli'),
                    'white_label' => function_exists('trinity_backup_has_feature') && trinity_backup_has_feature('white_label'),
                ],
            ];

            wp_send_json($data);
        } catch (\Throwable $throwable) {
            wp_send_json_error(['message' => $throwable->getMessage()]);
        }
    }

    public function handleSavePreUpdateSettings(): void
    {
        $this->assertNonce();
        $this->assertCapability();
        $this->assertProFeature('pre_update');

        if (!class_exists(PreUpdateBackups::class)) {
            wp_send_json_error(['message' => 'Pre-update backups module is not available. Activate Trinity Backup Pro.'], 503);
        }

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in assertNonce()
        $enabled = !empty($_POST['enabled']);
        $blockUpdates = !empty($_POST['block_updates']);
        $noMedia = !empty($_POST['no_media']);
        $noPlugins = !empty($_POST['no_plugins']);
        $noThemes = !empty($_POST['no_themes']);
        $noDatabase = !empty($_POST['no_database']);
        $noSpam = !empty($_POST['no_spam_comments']);
        $noEmailReplace = !empty($_POST['no_email_replace']);
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        update_option(PreUpdateBackups::OPTION_SETTINGS, [
            'enabled' => $enabled,
            'block_updates' => $blockUpdates,
            'no_media' => $noMedia,
            'no_plugins' => $noPlugins,
            'no_themes' => $noThemes,
            'no_database' => $noDatabase,
            'no_spam_comments' => $noSpam,
            'no_email_replace' => $noEmailReplace,
        ]);

        wp_send_json([
            'status' => 'ok',
            'message' => 'Pre-update settings saved.',
            'preupdate' => PreUpdateBackups::getSettings(),
        ]);
    }

    private function getLockOwner(): string
    {
        $userId = (int) get_current_user_id();
        return $userId > 0 ? 'user:' . $userId : 'user:unknown';
    }

    private function isTrinityEncryptionSupported(): bool
    {
        return TrinityCompressor::isEncryptionSupported();
    }

    private function getExportOptions(): array
    {
        $options = [];
        
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in calling method
        if (!empty($_POST['no_media'])) {
            $options['no_media'] = true;
        }
        if (!empty($_POST['no_plugins'])) {
            $options['no_plugins'] = true;
        }
        if (!empty($_POST['no_themes'])) {
            $options['no_themes'] = true;
        }
        if (!empty($_POST['no_database'])) {
            $options['no_database'] = true;
        }
        if (!empty($_POST['no_spam_comments'])) {
            $options['no_spam_comments'] = true;
        }
        if (!empty($_POST['password'])) {
            // Encryption requires Pro license
            if (function_exists('trinity_backup_has_feature') && trinity_backup_has_feature('encryption')) {
                $options['password'] = sanitize_text_field(wp_unslash((string) $_POST['password']));
            }
        }
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        
        return $options;
    }

    private function assertNonce(): void
    {
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash((string) $_POST['nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'trinity_backup')) {
            wp_send_json_error(['message' => 'Invalid nonce.'], 403);
        }
    }

    private function assertCapability(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions.'], 403);
        }
    }

    /**
     * Assert that a Pro feature is available; send 403 if not.
     */
    private function assertProFeature(string $feature): void
    {
        if (!function_exists('trinity_backup_has_feature') || !trinity_backup_has_feature($feature)) {
            wp_send_json_error(['message' => 'This feature requires a Pro license.'], 403);
        }
    }

    private function isAllowedArchive(string $archivePath): bool
    {
        $uploads = wp_upload_dir();
        $base = realpath($uploads['basedir']);
        $path = realpath($archivePath);

        if ($base === false || $path === false) {
            return false;
        }

        return str_starts_with($path, $base);
    }

    public function handleSaveTheme(): void
    {
        $this->assertNonce();
        $this->assertCapability();

        $theme = isset($_POST['theme']) ? sanitize_text_field(wp_unslash((string) $_POST['theme'])) : 'auto';
        
        if (!in_array($theme, ['light', 'dark', 'auto'], true)) {
            $theme = 'auto';
        }

        $userId = get_current_user_id();
        update_user_meta($userId, 'trinity_backup_theme', $theme);

        wp_send_json_success(['theme' => $theme]);
    }

    public function handleSaveEmailSettings(): void
    {
        $this->assertNonce();
        $this->assertCapability();
        $this->assertProFeature('email');

        if (!class_exists(EmailNotifier::class)) {
            wp_send_json_error(['message' => 'Email notifications module is not available. Activate Trinity Backup Pro.'], 503);
        }

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in assertNonce()
        EmailNotifier::saveSettings([
            'enabled'         => !empty($_POST['enabled']),
            'recipients'      => isset($_POST['recipients']) ? sanitize_text_field(wp_unslash((string) $_POST['recipients'])) : '',
            'on_manual'       => !empty($_POST['on_manual']),
            'on_scheduled'    => !empty($_POST['on_scheduled']),
            'on_pre_update'   => !empty($_POST['on_pre_update']),
            'on_import'       => !empty($_POST['on_import']),
            'on_failure_only' => !empty($_POST['on_failure_only']),
        ]);
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        wp_send_json([
            'status'  => 'ok',
            'message' => 'Email notification settings saved.',
            'email'   => EmailNotifier::getSettings(),
        ]);
    }

    public function handleSaveWhiteLabelSettings(): void
    {
        $this->assertNonce();
        $this->assertCapability();
        $this->assertProFeature('white_label');

        if (!class_exists(WhiteLabel::class)) {
            wp_send_json_error(['message' => 'White-label module is not available. Activate Trinity Backup Pro.'], 503);
        }

        if (!WhiteLabel::canCurrentUserAccessSettings()) {
            wp_send_json_error(['message' => 'You do not have permission to manage White Label settings.'], 403);
        }

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in assertNonce()
        WhiteLabel::saveSettings([
            'enabled'            => !empty($_POST['enabled']),
            'plugin_name'        => isset($_POST['plugin_name']) ? sanitize_text_field(wp_unslash((string) $_POST['plugin_name'])) : '',
            'plugin_description' => isset($_POST['plugin_description']) ? sanitize_text_field(wp_unslash((string) $_POST['plugin_description'])) : '',
            'author_name'        => isset($_POST['author_name']) ? sanitize_text_field(wp_unslash((string) $_POST['author_name'])) : '',
            'author_url'         => isset($_POST['author_url']) ? esc_url_raw(wp_unslash((string) $_POST['author_url'])) : '',
            'menu_icon'          => isset($_POST['menu_icon']) ? sanitize_text_field(wp_unslash((string) $_POST['menu_icon'])) : '',
            'hide_branding'      => !empty($_POST['hide_branding']),
            'hide_account_menu'  => !empty($_POST['hide_account_menu']),
            'hide_contact_menu'  => !empty($_POST['hide_contact_menu']),
            'hide_view_details'  => !empty($_POST['hide_view_details']),
            'only_deactivate_action' => !empty($_POST['only_deactivate_action']),
            'visible_user_id'    => isset($_POST['visible_user_id']) ? (int) $_POST['visible_user_id'] : 0,
        ]);
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        wp_send_json([
            'status'     => 'ok',
            'message'    => 'White-label settings saved.',
            'whitelabel' => WhiteLabel::getSettings(),
        ]);
    }
}
