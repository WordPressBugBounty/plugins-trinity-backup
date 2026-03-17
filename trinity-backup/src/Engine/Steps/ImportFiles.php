<?php
/**
 * Import files from .trinity archive format.
 * 
 * @package TrinityBackup
 * 
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_chmod
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_touch
 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
 * 
 * Reason: File import requires direct file handles for streaming operations
 * and preserving file permissions/timestamps.
 */

declare(strict_types=1);

namespace TrinityBackup\Engine\Steps;

if (!\defined('ABSPATH')) {
    exit;
}

use RuntimeException;
use TrinityBackup\Archiver\TrinityExtractor;
use TrinityBackup\Filesystem\FilesystemInterface;

/**
 * Import files from .trinity archive format.
 * Supports streaming reads and resumable large files.
 */
final class ImportFiles
{
    private FilesystemInterface $filesystem;

    public function __construct(FilesystemInterface $filesystem)
    {
        $this->filesystem = $filesystem;
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    public function run(array $state, int $timeLimit, int $fileLimit): array
    {
        $start = microtime(true);

        if ($state['stage'] === 'import_files') {
            $this->extractFiles($state, $timeLimit, $fileLimit, $start);
        }

        if ($state['stage'] === 'apply_manifest') {
            $this->applyManifest($state, $timeLimit, $start);
        }

        // After files, we go to import_db (not done)
        $status = 'continue';

        $stats = $state['stats'];
        
        // Add current file to stats for progress display
        if (!empty($state['current_file_name'])) {
            $stats['current_file'] = $state['current_file_name'];
        }

        $result = [
            'status' => $status,
            'stage' => $state['stage'],
            'progress' => $this->progress($state),
            'message' => $this->getMessage($state),
            'stats' => $stats,
            'state' => $state,
        ];
        
        return $result;
    }

    /** @param array<string, mixed> $state */
    private function extractFiles(array &$state, int $timeLimit, int $fileLimit, float $start): void
    {
        $archivePath = (string) $state['archive_path'];
        $root = rtrim((string) $state['file_root'], "/\\");
        
        // Archive position tracking
        $archiveOffset = (int) ($state['archive_offset'] ?? 0);
        $currentFileOffset = (int) ($state['current_file_offset'] ?? 0);
        
        // Files to skip during extraction
        $skipEntries = [
            'database.sql',
            'manifest.jsonl', 
            '_encryption_signature',
            '_site_info.json',
        ];
        
        // Open extractor
        $extractor = new TrinityExtractor($archivePath);
        
        // Set password if provided
        $password = $state['options']['password'] ?? '';
        if ($password !== '') {
            $extractor->setPassword($password);
        }
        
        $extractor->open();
        
        // Resume from position
        if ($archiveOffset > 0) {
            $extractor->setFilePointer($archiveOffset);
        }
        
        $processed = 0;
        
        try {
            while ((microtime(true) - $start) < $timeLimit && $processed < $fileLimit) {
                $bytesRead = 0;
                $remainingTime = $timeLimit - (microtime(true) - $start);
                
                $result = $extractor->extractNext(
                    $root,
                    $bytesRead,
                    $currentFileOffset,
                    (int) max(1, $remainingTime),
                    $skipEntries
                );
                
                if ($result['eof']) {
                    // End of archive
                    $state['stage'] = 'apply_manifest';
                    break;
                }
                
                $file = $result['file'];
                
                // File was skipped by extractor
                if ($result['skipped'] ?? false) {
                    $currentFileOffset = 0;
                    continue;
                }
                
                // Check for unsafe paths
                if ($file !== null && $this->isUnsafeEntry($file)) {
                    $currentFileOffset = 0;
                    continue;
                }
                
                if ($result['done']) {
                    // File complete
                    $currentFileOffset = 0;
                    $processed++;
                    $state['stats']['files']++;
                    $state['current_file_name'] = null;
                } else {
                    // Large file needs continuation
                    $state['current_file_offset'] = $currentFileOffset;
                    $state['current_file_name'] = $file;
                    $state['archive_offset'] = $extractor->getFilePointer();
                    $extractor->close();
                    return;
                }
            }
            
            $state['archive_offset'] = $extractor->getFilePointer();
            $state['current_file_offset'] = $currentFileOffset;
            
        } finally {
            $extractor->close();
        }
    }

    /** @param array<string, mixed> $state */
    private function applyManifest(array &$state, int $timeLimit, float $start): void
    {
        $manifestPath = (string) ($state['manifest_path'] ?? '');
        if ($manifestPath === '' || !is_file($manifestPath)) {
            $state['stage'] = 'done';
            return;
        }

        $offset = (int) ($state['manifest_offset'] ?? 0);
        $root = rtrim((string) $state['file_root'], "/\\");

        $handle = fopen($manifestPath, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Failed to open manifest: ' . $manifestPath);
        }

        if ($offset > 0 && fseek($handle, $offset) !== 0) {
            fclose($handle);
            throw new RuntimeException('Failed to seek manifest file.');
        }

        while (!feof($handle) && (microtime(true) - $start) < $timeLimit) {
            $line = fgets($handle);
            if ($line === false) {
                break;
            }

            $entry = json_decode(trim($line), true);
            if (!is_array($entry) || !isset($entry['path'])) {
                continue;
            }

            $path = ltrim(str_replace('\\', '/', (string) $entry['path']), '/');
            if ($this->isUnsafeEntry($path)) {
                continue;
            }

            $absolute = $root . '/' . $path;
            if (!file_exists($absolute)) {
                continue;
            }

            if (isset($entry['mode']) && is_int($entry['mode'])) {
                @chmod($absolute, $entry['mode']);
            }

            if (isset($entry['mtime']) && is_int($entry['mtime'])) {
                @touch($absolute, $entry['mtime']);
            }
        }

        $state['manifest_offset'] = ftell($handle);
        $completed = feof($handle);
        fclose($handle);

        if ($completed) {
            // After files are done, go to import_db (database imported LAST)
            $state['stage'] = 'import_db';
        }
    }

    private function getMessage(array $state): string
    {
        if ($state['stage'] === 'import_db') {
            return 'Files imported. Starting database import...';
        }
        if ($state['stage'] === 'done') {
            return 'Import complete.';
        }
        
        if (!empty($state['current_file_name'])) {
            return 'Importing: ' . basename($state['current_file_name']) . '...';
        }
        
        return 'Importing files...';
    }

    /** @param array<string, mixed> $state */
    private function progress(array $state): int
    {
        if ($state['stage'] === 'import_db') {
            // Files done, database next (70%)
            return 70;
        }

        if ($state['stage'] === 'apply_manifest') {
            return 65;
        }

        // Progress based on archive position (5% to 65%)
        $archiveOffset = (int) ($state['archive_offset'] ?? 0);
        $archiveSize = (int) ($state['archive_size'] ?? 0);
        
        if ($archiveSize > 0) {
            $ratio = min(1, $archiveOffset / $archiveSize);
            return 5 + (int) round($ratio * 60);
        }

        return 30;
    }

    private function isUnsafeEntry(string $entry): bool
    {
        return str_contains($entry, '../') || str_contains($entry, '..\\') || str_starts_with($entry, '/');
    }
}
