<?php

declare(strict_types=1);

namespace TrinityBackup\Core;

if (!\defined('ABSPATH')) {
    exit;
}

/**
 * Manages backup files listing, cleanup, and metadata.
 */
final class BackupManager
{
    private string $backupDir;

    public function __construct()
    {
        $uploads = wp_upload_dir();
        $this->backupDir = trailingslashit($uploads['basedir']) . 'trinity-backup';
    }

    /**
     * List all completed backups.
     * @return array<int, array{id: string, filename: string, size: int, created: int, url: string, origin: string}>
     */
    public function listBackups(): array
    {
        $backups = [];

        if (!is_dir($this->backupDir)) {
            return $backups;
        }

        $uploads = wp_upload_dir();

        // Find backups in subdirectories (domain-date-time-random)
        $dirs = glob($this->backupDir . '/*', GLOB_ONLYDIR);
        if ($dirs !== false) {
            foreach ($dirs as $dir) {
                $backupId = basename($dir);

                $origin = 'manual';
                $metaPath = $dir . '/meta.json';
                if (is_file($metaPath)) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading from uploads.
                    $raw = file_get_contents($metaPath);
                    if (is_string($raw) && $raw !== '') {
                        $decoded = json_decode($raw, true);
                        if (is_array($decoded) && isset($decoded['origin']) && in_array((string) $decoded['origin'], ['manual', 'scheduled', 'pre_update'], true)) {
                            $origin = (string) $decoded['origin'];
                        }
                    }
                }
                
                // Look for {backupId}.trinity or legacy backup.trinity
                $trinityPath = $dir . '/' . $backupId . '.trinity';
                if (!is_file($trinityPath)) {
                    // Fallback to legacy backup.trinity
                    $trinityPath = $dir . '/backup.trinity';
                    if (!is_file($trinityPath)) {
                        continue;
                    }
                }

                $backups[] = [
                    'id' => $backupId,
                    'filename' => $backupId . '.trinity',
                    'size' => filesize($trinityPath) ?: 0,
                    'created' => filemtime($trinityPath) ?: 0,
                    'url' => trailingslashit($uploads['baseurl']) . 'trinity-backup/' . $backupId . '/' . basename($trinityPath),
                    'path' => $trinityPath,
                    'origin' => $origin,
                ];
            }
        }
        
        // Also find .trinity files directly in backup folder (legacy/uploaded)
        $legacyFiles = glob($this->backupDir . '/*.trinity');
        if ($legacyFiles !== false) {
            foreach ($legacyFiles as $trinityPath) {
                $filename = basename($trinityPath);
                $id = pathinfo($filename, PATHINFO_FILENAME);

                $origin = 'manual';
                $metaPath = $this->backupDir . '/' . $id . '.meta.json';
                if (is_file($metaPath)) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading from uploads.
                    $raw = file_get_contents($metaPath);
                    if (is_string($raw) && $raw !== '') {
                        $decoded = json_decode($raw, true);
                        if (is_array($decoded) && isset($decoded['origin']) && in_array((string) $decoded['origin'], ['manual', 'scheduled', 'pre_update'], true)) {
                            $origin = (string) $decoded['origin'];
                        }
                    }
                }
                
                $backups[] = [
                    'id' => $id,
                    'filename' => $filename,
                    'size' => filesize($trinityPath) ?: 0,
                    'created' => filemtime($trinityPath) ?: 0,
                    'url' => trailingslashit($uploads['baseurl']) . 'trinity-backup/' . $filename,
                    'path' => $trinityPath,
                    'origin' => $origin,
                ];
            }
        }

        // Sort by date descending
        usort($backups, fn($a, $b) => $b['created'] <=> $a['created']);

        return $backups;
    }

    /**
     * Delete a backup by identifier.
     * Supports:
     * - New folder backups: {backupId}/{backupId}.trinity (and legacy {backupId}/backup.trinity)
     * - Legacy standalone files: trinity-backup/{filename}.trinity
     * - Older job folders: trinity-backup/job_{id}/...
     */
    public function deleteBackup(string $identifier): bool
    {
        $identifier = sanitize_file_name($identifier);

        if ($identifier === '' || str_contains($identifier, '..') || str_contains($identifier, '/')) {
            return false;
        }

        // If passed a direct .trinity file in the backup root, delete it.
        if (str_ends_with($identifier, '.trinity')) {
            $legacyPath = $this->backupDir . '/' . $identifier;
            if (is_file($legacyPath)) {
                return wp_delete_file($legacyPath);
            }
        }

        // Determine backupId from identifier (either id or {id}.trinity)
        $backupId = preg_replace('/\.trinity$/', '', $identifier);
        if (!is_string($backupId) || $backupId === '') {
            return false;
        }

        // New format: trinity-backup/{backupId}/
        $newDir = $this->backupDir . '/' . $backupId;
        if (is_dir($newDir)) {
            return $this->deleteDirectory($newDir);
        }

        // Legacy job folder format: trinity-backup/job_{id}/
        $jobDir = $this->backupDir . '/' . (str_starts_with($backupId, 'job_') ? $backupId : ('job_' . $backupId));
        if (is_dir($jobDir)) {
            return $this->deleteDirectory($jobDir);
        }

        // Fallback: standalone .trinity file by id
        $standalone = $this->backupDir . '/' . $backupId . '.trinity';
        if (is_file($standalone)) {
            return wp_delete_file($standalone);
        }

        return false;
    }

    /**
     * Cleanup old job folders (incomplete jobs older than X hours).
     */
    public function cleanupOldJobs(int $maxAgeHours = 24): int
    {
        $deleted = 0;
        $maxAge = time() - ($maxAgeHours * 3600);

        if (!is_dir($this->backupDir)) {
            return $deleted;
        }

        // Cleanup incomplete job folders
        $dirs = glob($this->backupDir . '/job_*', GLOB_ONLYDIR);
        if ($dirs === false) {
            $dirs = [];
        }
        
        // Also cleanup old upload temp folders
        $uploadDirs = glob($this->backupDir . '/uploads/*', GLOB_ONLYDIR);
        if ($uploadDirs !== false) {
            $dirs = array_merge($dirs, $uploadDirs);
        }

        foreach ($dirs as $dir) {
            $mtime = filemtime($dir);

            // Skip recent directories
            if ($mtime !== false && $mtime > $maxAge) {
                continue;
            }

            // Skip directories with completed backups (they have backup.trinity)
            $hasBackup = is_file($dir . '/backup.trinity');
            if ($hasBackup) {
                continue;
            }

            // This is an incomplete/abandoned job or upload temp folder - delete it
            if ($this->deleteDirectory($dir)) {
                $deleted++;
            }
        }
        
        // Also try to cleanup empty uploads folder
        $uploadsDir = $this->backupDir . '/uploads';
        if (is_dir($uploadsDir)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removing empty temp directory
            @rmdir($uploadsDir); // Only removes if empty
        }

        return $deleted;
    }

    /**
     * Get total size of all backups.
     */
    public function getTotalSize(): int
    {
        $total = 0;
        foreach ($this->listBackups() as $backup) {
            $total += $backup['size'];
        }
        return $total;
    }

    /**
     * Get backup directory path.
     */
    public function getBackupDir(): string
    {
        return $this->backupDir;
    }

    /**
     * Recursively delete a directory.
     */
    private function deleteDirectory(string $dir): bool
    {
        if (!is_dir($dir)) {
            return false;
        }

        $files = array_diff(scandir($dir) ?: [], ['.', '..']);

        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                wp_delete_file($path);
            }
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Recursive directory deletion
        return rmdir($dir);
    }

    /**
     * Format file size for display.
     */
    public static function formatSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }
}
