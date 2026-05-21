<?php

declare(strict_types=1);

namespace TrinityBackup\Core;

if (!\defined('ABSPATH')) {
    exit;
}

use RuntimeException;

final class StorageSecurity
{
    private const DIR_NAME = 'trinity-backup';
    private const STATE_DIR = '.state';

    private const HTACCESS = "Options -Indexes\n"
        . "<IfModule mod_authz_core.c>\n"
        . "Require all denied\n"
        . "</IfModule>\n"
        . "<IfModule !mod_authz_core.c>\n"
        . "Deny from all\n"
        . "</IfModule>\n";

    private const WEB_CONFIG = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
        . "<configuration>\n"
        . "  <system.webServer>\n"
        . "    <security>\n"
        . "      <authorization>\n"
        . "        <remove users=\"*\" roles=\"\" verbs=\"\" />\n"
        . "        <add accessType=\"Deny\" users=\"*\" />\n"
        . "      </authorization>\n"
        . "    </security>\n"
        . "  </system.webServer>\n"
        . "</configuration>\n";

    public static function install(): void
    {
        self::ensureBaseDirectory();
        self::migrateLegacyStateFiles();
        self::deleteLegacyPublicFiles();
        self::cleanupLegacyTemporaryArtifacts();
    }

    public static function getBaseDir(): string
    {
        $uploads = wp_upload_dir();
        return trailingslashit($uploads['basedir']) . self::DIR_NAME;
    }

    public static function ensureBaseDirectory(): string
    {
        $dir = self::getBaseDir();
        self::ensureDirectory($dir);
        self::writeProtectionFiles($dir);
        self::deleteLegacyPublicFiles();

        return $dir;
    }

    public static function ensureJobDirectory(string $jobId): string
    {
        $baseDir = self::ensureBaseDirectory();
        $dir = $baseDir . '/' . sanitize_file_name($jobId);
        self::ensureDirectory($dir);
        self::writeIndexFile($dir);

        return $dir;
    }

    public static function ensureUploadDirectory(string $uploadId): string
    {
        $baseDir = self::ensureBaseDirectory();
        $uploadsDir = $baseDir . '/uploads';
        self::ensureDirectory($uploadsDir);
        self::writeIndexFile($uploadsDir);

        $dir = $uploadsDir . '/' . sanitize_file_name($uploadId);
        self::ensureDirectory($dir);
        self::writeIndexFile($dir);

        return $dir;
    }

    public static function getStateDir(): string
    {
        $dir = self::ensureBaseDirectory() . '/' . self::STATE_DIR;
        self::ensureDirectory($dir);
        self::writeProtectionFiles($dir);

        return $dir;
    }

    public static function buildDownloadUrl(string $backupId): string
    {
        $backupId = sanitize_file_name($backupId);

        return add_query_arg(
            [
                'action' => 'trinity_backup_download',
                'backup' => $backupId,
                'nonce' => wp_create_nonce('trinity_backup_download_' . $backupId),
            ],
            admin_url('admin-ajax.php')
        );
    }

    public static function deleteFileInsideBase(string $path): void
    {
        $realPath = realpath($path);
        if ($realPath === false || !is_file($realPath)) {
            return;
        }

        if (!self::isPathInsideBase($realPath)) {
            return;
        }

        wp_delete_file($realPath);
    }

    public static function isPathInsideBase(string $path): bool
    {
        $base = realpath(self::ensureBaseDirectory());
        $realPath = realpath($path);

        if ($base === false || $realPath === false) {
            return false;
        }

        $base = rtrim(str_replace('\\', '/', $base), '/') . '/';
        $realPath = str_replace('\\', '/', $realPath);

        return str_starts_with($realPath, $base);
    }

    public static function getLegacyStateFilePath(string $jobId): string
    {
        return self::getBaseDir() . '/' . sanitize_file_name($jobId) . '_state.json';
    }

    public static function deleteLegacyPublicFiles(): void
    {
        $baseDir = self::getBaseDir();

        foreach (['_current_job.txt', '_operation_lock.json'] as $filename) {
            $path = $baseDir . '/' . $filename;
            if (is_file($path)) {
                wp_delete_file($path);
            }
        }
    }

    public static function migrateLegacyStateFiles(): void
    {
        $baseDir = self::getBaseDir();
        if (!is_dir($baseDir)) {
            return;
        }

        $stateDir = $baseDir . '/' . self::STATE_DIR;
        self::ensureDirectory($stateDir);
        self::writeProtectionFiles($stateDir);

        $files = glob($baseDir . '/*_state.json');
        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }

            $jobId = preg_replace('/_state\.json$/', '', basename($file));
            if (!is_string($jobId) || $jobId === '') {
                continue;
            }

            $target = $stateDir . '/' . sanitize_file_name($jobId) . '.json';
            if (!is_file($target)) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rename -- Moving plugin-owned legacy state file.
                if (!@rename($file, $target)) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- Migrating plugin-owned legacy state file.
                    @copy($file, $target);
                    wp_delete_file($file);
                }
            } else {
                wp_delete_file($file);
            }
        }
    }

    public static function cleanupLegacyTemporaryArtifacts(): void
    {
        $baseDir = self::getBaseDir();
        if (!is_dir($baseDir)) {
            return;
        }

        $currentJob = get_option('trinity_backup_current_job');
        $currentJob = is_string($currentJob) ? $currentJob : '';

        foreach (['database.sql', 'manifest.jsonl'] as $filename) {
            $rootPath = $baseDir . '/' . $filename;
            if (is_file($rootPath)) {
                wp_delete_file($rootPath);
            }
        }

        $dirs = glob($baseDir . '/*', GLOB_ONLYDIR);
        if ($dirs === false) {
            return;
        }

        foreach ($dirs as $dir) {
            $jobId = basename($dir);
            if ($jobId === self::STATE_DIR || $jobId === 'uploads' || $jobId === $currentJob) {
                continue;
            }

            foreach (['database.sql', 'manifest.jsonl'] as $filename) {
                $path = $dir . '/' . $filename;
                if (is_file($path)) {
                    wp_delete_file($path);
                }
            }
        }
    }

    private static function ensureDirectory(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }

        if (!wp_mkdir_p($dir) && !is_dir($dir)) {
            throw new RuntimeException('Failed to create backup directory: ' . $dir);
        }
    }

    private static function writeProtectionFiles(string $dir): void
    {
        self::writeFileIfChanged($dir . '/.htaccess', self::HTACCESS);
        self::writeFileIfChanged($dir . '/web.config', self::WEB_CONFIG);
        self::writeIndexFile($dir);
    }

    private static function writeIndexFile(string $dir): void
    {
        self::writeFileIfChanged($dir . '/index.php', "<?php\nhttp_response_code(403);\nexit;\n");
    }

    private static function writeFileIfChanged(string $path, string $content): void
    {
        if (is_file($path)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading plugin-owned protection file.
            $existing = file_get_contents($path);
            if ($existing === $content) {
                return;
            }
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing plugin-owned protection file.
        if (@file_put_contents($path, $content, LOCK_EX) === false) {
            throw new RuntimeException('Failed to write backup directory protection file: ' . $path);
        }
    }
}