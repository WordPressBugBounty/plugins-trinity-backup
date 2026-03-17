<?php

declare(strict_types=1);

namespace TrinityBackup\Core;

if (!\defined('ABSPATH')) {
    exit;
}

/**
 * Compatibility checker for import operations.
 */
final class CompatibilityChecker
{
    /** @var array<string, array{status: string, message: string}> */
    private array $checks = [];

    /**
     * Run all compatibility checks.
     */
    public function runAll(string $archivePath = ''): array
    {
        $this->checks = [];

        $this->checkPhpVersion();
        $this->checkPhpExtensions();
        $this->checkDiskSpace();
        $this->checkMemoryLimit();
        $this->checkMaxExecutionTime();
        $this->checkUploadSize();
        $this->checkWritePermissions();

        if ($archivePath !== '') {
            $this->checkArchive($archivePath);
        }

        return $this->getResults();
    }

    /**
     * Check PHP version.
     */
    private function checkPhpVersion(): void
    {
        $required = '8.0.0';
        $current = PHP_VERSION;

        if (version_compare($current, $required, '>=')) {
            $this->checks['php_version'] = [
                'status' => 'ok',
                'message' => sprintf('PHP %s (required: %s)', $current, $required),
            ];
        } else {
            $this->checks['php_version'] = [
                'status' => 'error',
                'message' => sprintf('PHP %s is too old (required: %s)', $current, $required),
            ];
        }
    }

    /**
     * Check required PHP extensions.
     */
    private function checkPhpExtensions(): void
    {
        $required = ['zip', 'mysqli', 'json', 'mbstring'];
        $missing = [];

        foreach ($required as $ext) {
            if (!extension_loaded($ext)) {
                $missing[] = $ext;
            }
        }

        if (empty($missing)) {
            $this->checks['php_extensions'] = [
                'status' => 'ok',
                'message' => 'All required extensions loaded',
            ];
        } else {
            $this->checks['php_extensions'] = [
                'status' => 'error',
                'message' => sprintf('Missing extensions: %s', implode(', ', $missing)),
            ];
        }
    }

    /**
     * Check available disk space.
     */
    private function checkDiskSpace(): void
    {
        $uploads = wp_upload_dir();
        $freeSpace = @disk_free_space($uploads['basedir']);
        $minRequired = 100 * 1024 * 1024; // 100 MB

        if ($freeSpace === false) {
            $this->checks['disk_space'] = [
                'status' => 'warning',
                'message' => 'Could not determine free disk space',
            ];
        } elseif ($freeSpace < $minRequired) {
            $this->checks['disk_space'] = [
                'status' => 'error',
                'message' => sprintf('Only %s free (minimum: 100 MB)', size_format($freeSpace)),
            ];
        } else {
            $this->checks['disk_space'] = [
                'status' => 'ok',
                'message' => sprintf('%s free disk space', size_format($freeSpace)),
            ];
        }
    }

    /**
     * Check PHP memory limit.
     */
    private function checkMemoryLimit(): void
    {
        $limit = ini_get('memory_limit');
        $bytes = $this->parseSize($limit);
        $minRequired = 64 * 1024 * 1024; // 64 MB

        if ($bytes === -1) {
            $this->checks['memory_limit'] = [
                'status' => 'ok',
                'message' => 'Unlimited memory',
            ];
        } elseif ($bytes < $minRequired) {
            $this->checks['memory_limit'] = [
                'status' => 'warning',
                'message' => sprintf('Memory limit %s is low (recommended: 64M+)', $limit),
            ];
        } else {
            $this->checks['memory_limit'] = [
                'status' => 'ok',
                'message' => sprintf('Memory limit: %s', $limit),
            ];
        }
    }

    /**
     * Check max execution time.
     */
    private function checkMaxExecutionTime(): void
    {
        $time = (int) ini_get('max_execution_time');

        if ($time === 0) {
            $this->checks['max_execution_time'] = [
                'status' => 'ok',
                'message' => 'No execution time limit',
            ];
        } elseif ($time < 30) {
            $this->checks['max_execution_time'] = [
                'status' => 'warning',
                'message' => sprintf('Execution time %ds is low (chunked processing will handle this)', $time),
            ];
        } else {
            $this->checks['max_execution_time'] = [
                'status' => 'ok',
                'message' => sprintf('Max execution time: %ds', $time),
            ];
        }
    }

    /**
     * Check upload size limits.
     */
    private function checkUploadSize(): void
    {
        $uploadMax = $this->parseSize(ini_get('upload_max_filesize'));
        $postMax = $this->parseSize(ini_get('post_max_size'));
        $effective = min($uploadMax, $postMax);

        $this->checks['upload_size'] = [
            'status' => $effective < 10 * 1024 * 1024 ? 'warning' : 'ok',
            'message' => sprintf('Max upload: %s', size_format($effective)),
        ];
    }

    /**
     * Check write permissions.
     */
    private function checkWritePermissions(): void
    {
        $uploads = wp_upload_dir();
        $testDir = trailingslashit($uploads['basedir']) . 'trinity-backup';

        if (!is_dir($testDir)) {
            if (!wp_mkdir_p($testDir)) {
                $this->checks['write_permissions'] = [
                    'status' => 'error',
                    'message' => 'Cannot create backup directory',
                ];
                return;
            }
        }

        $testFile = $testDir . '/.write-test-' . time();
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Testing write permissions
        if (@file_put_contents($testFile, 'test') === false) {
            $this->checks['write_permissions'] = [
                'status' => 'error',
                'message' => 'Backup directory is not writable',
            ];
        } else {
            wp_delete_file($testFile);
            $this->checks['write_permissions'] = [
                'status' => 'ok',
                'message' => 'Backup directory is writable',
            ];
        }
    }

    /**
     * Check archive file for import.
     */
    private function checkArchive(string $archivePath): void
    {
        if (!is_file($archivePath)) {
            $this->checks['archive'] = [
                'status' => 'error',
                'message' => 'Archive file not found',
            ];
            return;
        }

        $size = filesize($archivePath);
        if ($size === false || $size === 0) {
            $this->checks['archive'] = [
                'status' => 'error',
                'message' => 'Archive file is empty',
            ];
            return;
        }

        $zip = new \ZipArchive();
        $result = $zip->open($archivePath);

        if ($result !== true) {
            $this->checks['archive'] = [
                'status' => 'error',
                'message' => 'Invalid or corrupted ZIP archive',
            ];
            return;
        }

        $hasDatabase = $zip->locateName('database.sql') !== false;
        $zip->close();

        if (!$hasDatabase) {
            $this->checks['archive'] = [
                'status' => 'error',
                'message' => 'Archive does not contain database.sql',
            ];
            return;
        }

        $this->checks['archive'] = [
            'status' => 'ok',
            'message' => sprintf('Valid archive (%s)', size_format($size)),
        ];
    }

    /**
     * Get check results.
     */
    public function getResults(): array
    {
        $hasErrors = false;
        $hasWarnings = false;

        foreach ($this->checks as $check) {
            if ($check['status'] === 'error') {
                $hasErrors = true;
            }
            if ($check['status'] === 'warning') {
                $hasWarnings = true;
            }
        }

        return [
            'status' => $hasErrors ? 'error' : ($hasWarnings ? 'warning' : 'ok'),
            'can_proceed' => !$hasErrors,
            'checks' => $this->checks,
        ];
    }

    /**
     * Parse PHP size string to bytes.
     */
    private function parseSize(string $size): int
    {
        $size = trim($size);

        if ($size === '-1') {
            return -1;
        }

        $last = strtolower($size[strlen($size) - 1]);
        $value = (int) $size;

        switch ($last) {
            case 'g':
                $value *= 1024;
                // fallthrough
            case 'm':
                $value *= 1024;
                // fallthrough
            case 'k':
                $value *= 1024;
        }

        return $value;
    }
}
