<?php

declare(strict_types=1);

namespace TrinityBackup\Engine;

if (!\defined('ABSPATH')) {
    exit;
}

use RuntimeException;
use TrinityBackup\Archiver\TrinityExtractor;
use TrinityBackup\Core\StateManager;
use TrinityBackup\Engine\Steps\ImportDatabase;
use TrinityBackup\Engine\Steps\ImportFiles;
use TrinityBackup\Filesystem\FilesystemInterface;

final class ImportPipeline
{
    private const DEFAULT_TIME_LIMIT = 20;
    private const DEFAULT_FILE_LIMIT = 100;

    private StateManager $stateManager;
    private FilesystemInterface $filesystem;
    private ImportDatabase $dbStep;
    private ImportFiles $filesStep;

    public function __construct(
        StateManager $stateManager,
        FilesystemInterface $filesystem,
        ImportDatabase $dbStep,
        ImportFiles $filesStep
    ) {
        $this->stateManager = $stateManager;
        $this->filesystem = $filesystem;
        $this->dbStep = $dbStep;
        $this->filesStep = $filesStep;
    }

    public function start(string $archivePath, array $options = []): array
    {
        if (!is_file($archivePath)) {
            throw new RuntimeException('Archive not found.');
        }

        if (!empty($options['password']) && !TrinityExtractor::isDecryptionSupported()) {
            throw new RuntimeException('This server does not support AES-256-GCM decryption (OpenSSL missing or cipher unavailable).');
        }

        $jobId = $this->stateManager->create();
        
        // Use the same directory where archive is located for temp files
        // This avoids creating duplicate folders
        $archiveDir = dirname($archivePath);
        $baseDir = $archiveDir;
        
        // If archive is in a job folder, use it; otherwise create temp folder
        if (!str_contains($archiveDir, 'trinity-backup/job_')) {
            $uploads = wp_upload_dir();
            $baseDir = trailingslashit($uploads['basedir']) . 'trinity-backup/' . $jobId;
            $this->filesystem->ensureDir($baseDir);
        }

        $dbPath = $baseDir . '/database.sql';
        $manifestPath = $baseDir . '/manifest.jsonl';
        
        // Get archive size for progress tracking
        $archiveSize = (int) filesize($archivePath);

        // Import order
        // 1. extract - extract database.sql and manifest.jsonl
        // 2. import_files - import files FIRST
        // 3. import_db - import database LAST (so job state is not lost)
        // 4. done
        
        $state = [
            'job_id' => $jobId,
            'job_type' => 'import',
            'stage' => 'extract',
            'options' => $options,
            'archive_path' => $archivePath,
            'archive_size' => $archiveSize,
            'db_path' => $dbPath,
            'manifest_path' => $manifestPath,
            'db_offset' => 0,
            'db_partial' => '',
            'archive_offset' => 0,
            'current_file_offset' => 0,
            'manifest_offset' => 0,
            'file_root' => WP_CONTENT_DIR,
            'url_replacement_done' => false,
            // Save current site URLs BEFORE database import (to use for replacement after)
            'target_site_url' => site_url(),
            'target_home_url' => home_url(),
            'stats' => [
                'files' => 0,
                'statements' => 0,
                'urls_replaced' => 0,
            ],
            'started_at' => time(),
        ];

        $this->stateManager->save($jobId, $state);

        return [
            'status' => 'continue',
            'job_id' => $jobId,
            'stage' => 'extract',
            'progress' => 0,
            'message' => 'Preparing import...',
        ];
    }

    public function run(string $jobId): array
    {
        $state = $this->stateManager->load($jobId);
        
        if ($state === null) {
            $fallback = $this->stateManager->loadCurrent();
            if (is_array($fallback) && ($fallback['job_type'] ?? '') === 'import') {
                $state = $fallback;
            }
        }
        if ($state === null) {
            return [
                'status' => 'error',
                'message' => 'Job not found.',
            ];
        }
        
        $timeLimit = $this->getTimeLimit();
        $response = [];

        // New order: extract -> import_files -> apply_manifest -> import_db -> done
        switch ($state['stage']) {
            case 'extract':
                $response = $this->processExtraction($state);
                break;
            case 'import_files':
            case 'apply_manifest':
                $response = $this->filesStep->run($state, $timeLimit, $this->getFileLimit());
                break;
            case 'import_db':
                // Pass URL replacements to database import step (runs LAST)
                $response = $this->dbStep->run($state, $timeLimit, $state['url_replacements'] ?? []);
                break;
            case 'done':
                $response = $this->buildDoneResponse($state);
                break;
            default:
                $response = [
                    'status' => 'error',
                    'message' => 'Unknown stage: ' . $state['stage'],
                ];
        }


        if (isset($response['state']) && is_array($response['state'])) {
            $state = $response['state'];
            unset($response['state']);
        }

        $this->stateManager->save($state['job_id'], $state);

        if ($state['stage'] === 'done') {
            $response = $this->buildDoneResponse($state);
        }

        return $response;
    }

    private function buildDoneResponse(array $state): array
    {
        $response = [
            'status' => 'done',
            'stage' => 'done',
            'progress' => 100,
            'message' => 'Import complete.',
            'stats' => $state['stats'],
        ];
        
        // Include URL change info for frontend (to handle redirect)
        if (!empty($state['url_changed'])) {
            $response['url_changed'] = true;
            $response['new_site_url'] = $state['new_site_url'] ?? '';
            $response['old_site_url'] = $state['old_site_url'] ?? '';
        }
        
        return $response;
    }

    private function getTimeLimit(): int
    {
        $value = (int) get_option('trinity_backup_time_limit', self::DEFAULT_TIME_LIMIT);
        if ($value < 5) {
            return 5;
        }

        return $value;
    }

    private function processExtraction(array $state): array
    {
        $archivePath = (string) $state['archive_path'];
        $dbPath = (string) $state['db_path'];
        $manifestPath = (string) $state['manifest_path'];
        $password = $state['options']['password'] ?? '';

        try {
            $extractor = new TrinityExtractor($archivePath);
            $extractor->open();
            
            // Check if archive is encrypted by presence of the signature entry.
            // The signature content itself is encrypted, so we can only validate it after setting a password.
            $isEncrypted = false;
            foreach ($extractor->listFiles() as $file) {
                $relativePath = $file['path'] !== '' ? $file['path'] . '/' . $file['name'] : $file['name'];
                if ($relativePath === '_encryption_signature') {
                    $isEncrypted = true;
                    break;
                }
            }
            
            if ($isEncrypted) {
                if ($password === '') {
                    throw new RuntimeException('This archive is password-protected. Please provide the password.');
                }

                // Set password and verify signature
                $extractor->setPassword($password);
                $signatureContent = $extractor->getFileContent('_encryption_signature');

                if ($signatureContent !== Steps\ExportFiles::ENCRYPTION_SIGNATURE) {
                    throw new RuntimeException('Incorrect password. Please check and try again.');
                }
            } elseif ($password !== '') {
                // Password provided but archive is not encrypted - just ignore password
            }

            // Read _site_info.json for URL replacement
            $siteInfoJson = $extractor->getFileContent('_site_info.json');
            $siteInfo = $siteInfoJson ? json_decode($siteInfoJson, true) : null;

            // Extract database.sql
            $extracted = $extractor->extractFile('database.sql', $dbPath);
            if (!$extracted) {
                throw new RuntimeException('Database entry not found in archive.');
            }

            // Extract manifest.jsonl if exists
            $extractor->extractFile('manifest.jsonl', $manifestPath);

            $extractor->close();

            // Prepare URL replacements
            $replacements = $this->prepareUrlReplacements($siteInfo, $state);
            
            // NEW ORDER: Go to import_files FIRST, then import_db
            $state['stage'] = 'import_files';
            $state['is_encrypted'] = $isEncrypted;
            $state['url_replacements'] = $replacements;
            $state['url_changed'] = !empty($replacements['old']) && $replacements['old'] !== $replacements['new'];
            $state['old_site_url'] = $siteInfo['site_url'] ?? '';
            $state['new_site_url'] = $state['target_site_url'];
            $this->stateManager->save($state['job_id'], $state);

            $message = 'Extraction complete. Importing files...';
            if (!empty($replacements['old'])) {
                $message .= sprintf(' (URL will change: %s → %s)', $replacements['old'], $replacements['new']);
            }

            return [
                'status' => 'continue',
                'stage' => 'import_files',
                'progress' => 5,
                'message' => $message,
                'state' => $state,
            ];

        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Prepare URL replacement arrays.
     * Creates multiple variations: plain, URL-encoded, JSON-escaped, etc.
     */
    private function prepareUrlReplacements(?array $siteInfo, array $state): array
    {
        $oldSiteUrl = $siteInfo['site_url'] ?? '';
        $newSiteUrl = $state['target_site_url'] ?? site_url();
        $noEmailReplace = $siteInfo['no_email_replace'] ?? false;

        // If same URL or no old URL, skip replacement
        if (empty($oldSiteUrl) || $oldSiteUrl === $newSiteUrl) {
            return ['search' => [], 'replace' => [], 'old' => '', 'new' => ''];
        }

        $search = [];
        $replace = [];

        $oldParts = wp_parse_url($oldSiteUrl);
        $newParts = wp_parse_url($newSiteUrl);

        // Get domains
        $oldDomain = is_array($oldParts) ? ($oldParts['host'] ?? '') : '';
        $newDomain = is_array($newParts) ? ($newParts['host'] ?? '') : '';

        // Get paths
        $oldPath = is_array($oldParts) ? (string) ($oldParts['path'] ?? '') : '';
        $newPath = is_array($newParts) ? (string) ($newParts['path'] ?? '') : '';

        // Get scheme
        $newScheme = is_array($newParts) ? (string) ($newParts['scheme'] ?? '') : '';

        // Multiple scheme variations (http, https, protocol-relative)
        $oldSchemes = ['http', 'https', ''];
        $newSchemes = [$newScheme, $newScheme, ''];

        for ($i = 0; $i < count($oldSchemes); $i++) {
            $oldUrl = $this->urlWithScheme(rtrim($oldSiteUrl, '/'), $oldSchemes[$i]);
            $newUrl = $this->urlWithScheme(rtrim($newSiteUrl, '/'), $newSchemes[$i]);

            // Plain URL
            if (!in_array($oldUrl, $search)) {
                $search[] = $oldUrl;
                $replace[] = $newUrl;
            }

            // URL encoded
            if (!in_array(urlencode($oldUrl), $search)) {
                $search[] = urlencode($oldUrl);
                $replace[] = urlencode($newUrl);
            }

            // JSON escaped (forward slashes)
            $jsonOld = addcslashes($oldUrl, '/');
            $jsonNew = addcslashes($newUrl, '/');
            if (!in_array($jsonOld, $search)) {
                $search[] = $jsonOld;
                $replace[] = $jsonNew;
            }
        }

        // Domain with path variations for serialized data
        if ($oldDomain && $newDomain) {
            // 'domain','path' format (serialized WP options)
            $oldDomainPath = sprintf("'%s','%s'", $oldDomain, trailingslashit($oldPath));
            $newDomainPath = sprintf("'%s','%s'", $newDomain, trailingslashit($newPath));
            if (!in_array($oldDomainPath, $search)) {
                $search[] = $oldDomainPath;
                $replace[] = $newDomainPath;
            }

            // Email domain replacement (if not disabled)
            if (!$noEmailReplace) {
                $oldEmail = '@' . $oldDomain;
                $newEmail = '@' . str_ireplace('www.', '', $newDomain);
                if (!in_array($oldEmail, $search)) {
                    $search[] = $oldEmail;
                    $replace[] = $newEmail;
                }
            }
        }

        return [
            'search' => $search,
            'replace' => $replace,
            'old' => $oldSiteUrl,
            'new' => $newSiteUrl,
        ];
    }

    private function urlWithScheme(string $url, string $scheme): string
    {
        if ($scheme === '') {
            // Protocol-relative
            return preg_replace('#^https?:#', '', $url);
        }
        return preg_replace('#^https?:#', $scheme . ':', $url);
    }

    private function getFileLimit(): int
    {
        $value = (int) get_option('trinity_backup_file_limit', self::DEFAULT_FILE_LIMIT);
        if ($value < 20) {
            return 20;
        }

        return $value;
    }
}
