<?php

declare(strict_types=1);

namespace TrinityBackup\Engine\Steps;

if (!\defined('ABSPATH')) {
    exit;
}

use TrinityBackup\Archiver\TrinityCompressor;
use TrinityBackup\Filesystem\FilesystemInterface;

/**
 * Export files to .trinity archive format.
 * Supports streaming writes and resumable large files.
 */
final class ExportFiles
{
    /** Signature text used to verify encryption password */
    public const ENCRYPTION_SIGNATURE = 'TRINITY_BACKUP_ENCRYPTED_ARCHIVE_v1';
    
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

        $archivePath = (string) $state['archive_path'];
        $root = (string) $state['file_root'];
        $offset = (int) $state['file_offset'];
        $exclude = (array) ($state['exclude_dirs'] ?? []);
        $manifestPath = (string) ($state['manifest_path'] ?? '');
        
        // Track current file being written (for large file continuation)
        $currentFile = $state['current_file'] ?? null;
        $currentFileOffset = (int) ($state['current_file_offset'] ?? 0);
        $archiveOffset = (int) ($state['archive_offset'] ?? 0);
        
        // Open compressor
        $compressor = new TrinityCompressor($archivePath);
        
        // Set encryption password if provided
        $password = $state['options']['password'] ?? '';
        if ($password !== '') {
            $compressor->setPassword($password);
        }
        
        $compressor->open();
        
        // Resume from archive position if continuing
        if ($archiveOffset > 0) {
            $compressor->setFilePointer($archiveOffset);
        }
        
        try {
            // Add encryption signature first (if encrypted and not already added)
            if ($password !== '' && empty($state['signature_added'])) {
                // Add a signature file to verify password on import
                // The content is a known string that will be encrypted
                $compressor->addFromString('_encryption_signature', self::ENCRYPTION_SIGNATURE);
                $state['signature_added'] = true;
            }
            
            // Add site info for automatic URL replacement on import
            if (empty($state['site_info_added'])) {
                $siteInfo = [
                    'site_url' => site_url(),
                    'home_url' => home_url(),
                    'no_email_replace' => !empty($state['options']['no_email_replace']),
                    'wp_version' => get_bloginfo('version'),
                    'export_date' => gmdate('Y-m-d H:i:s'),
                    'trinity_version' => defined('TRINITY_BACKUP_VERSION') ? TRINITY_BACKUP_VERSION : '1.0.0',
                ];
                $compressor->addFromString('_site_info.json', json_encode($siteInfo, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                $state['site_info_added'] = true;
            }
            
            // Add database.sql
            if (empty($state['db_added'])) {
                $dbPath = (string) $state['db_path'];
                $bytesWritten = 0;
                $dbOffset = 0;
                
                // Set current file name for progress display before starting
                $state['current_file_name'] = 'database.sql';
                
                $complete = $compressor->addFile($dbPath, 'database.sql', $bytesWritten, $dbOffset, $timeLimit);
                
                if ($complete) {
                    $state['db_added'] = true;
                    $state['current_file_name'] = null;
                } else {
                    // DB file is huge and needs continuation
                    $state['current_file'] = $dbPath;
                    $state['current_file_offset'] = $dbOffset;
                    $state['archive_offset'] = $compressor->getFilePointer();
                    $compressor->truncate();
                    $compressor->close();
                    
                    return $this->buildResponse($state, 'continue');
                }
            }
            
            // Continue writing a large file from previous request
            if ($currentFile !== null && $currentFileOffset > 0) {
                $archiveName = $state['current_file_name'] ?? basename($currentFile);
                $bytesWritten = 0;
                
                $remainingTime = $timeLimit - (microtime(true) - $start);
                $complete = $compressor->addFile($currentFile, $archiveName, $bytesWritten, $currentFileOffset, (int) $remainingTime);
                
                if (!$complete) {
                    // Still not done
                    $state['current_file_offset'] = $currentFileOffset;
                    $state['archive_offset'] = $compressor->getFilePointer();
                    $compressor->truncate();
                    $compressor->close();
                    
                    return $this->buildResponse($state, 'continue');
                }
                
                // File complete, clear tracking
                $state['current_file'] = null;
                $state['current_file_name'] = null;
                $state['current_file_offset'] = 0;
                $offset++;
                $state['stats']['files']++;
            }
            
            // Process files
            while ((microtime(true) - $start) < $timeLimit) {
                $batch = $this->filesystem->listFiles($root, $offset, $fileLimit, $exclude);

                if (empty($batch)) {
                    $state['stage'] = 'done';
                    break;
                }

                foreach ($batch as $path) {
                    $relative = ltrim(str_replace($root, '', $path), '/');
                    $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
                    
                    $bytesWritten = 0;
                    $fileOffset = 0;
                    $remainingTime = $timeLimit - (microtime(true) - $start);
                    
                    $complete = $compressor->addFile($path, $relative, $bytesWritten, $fileOffset, (int) max(1, $remainingTime));
                    
                    if (!$complete) {
                        // Large file needs continuation
                        $state['current_file'] = $path;
                        $state['current_file_name'] = $relative;
                        $state['current_file_offset'] = $fileOffset;
                        $state['archive_offset'] = $compressor->getFilePointer();
                        $state['file_offset'] = $offset;
                        $compressor->truncate();
                        $compressor->close();
                        
                        return $this->buildResponse($state, 'continue');
                    }
                    
                    $this->appendManifest($manifestPath, $relative, $path);
                    $offset++;
                    $state['stats']['files']++;

                    if ((microtime(true) - $start) >= $timeLimit) {
                        break 2;
                    }
                }
            }

            // Add manifest at the end
            if ($state['stage'] === 'done' && empty($state['manifest_added']) && $manifestPath !== '' && is_file($manifestPath)) {
                $bytesWritten = 0;
                $fileOffset = 0;
                $compressor->addFile($manifestPath, 'manifest.jsonl', $bytesWritten, $fileOffset, 30);
                $state['manifest_added'] = true;
            }
            
            // Finalize if done
            if ($state['stage'] === 'done') {
                $compressor->finalize();
            } else {
                $compressor->truncate();
            }
            
        } finally {
            $compressor->close();
        }

        $state['file_offset'] = $offset;
        $state['archive_offset'] = 0; // Reset for next batch
        $state['current_file'] = null;
        $state['current_file_offset'] = 0;

        return $this->buildResponse($state, $state['stage'] === 'done' ? 'done' : 'continue');
    }
    
    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function buildResponse(array $state, string $status): array
    {
        $stats = $state['stats'] ?? [];
        
        // Add current file to stats for progress display
        if (!empty($state['current_file_name'])) {
            $stats['current_file'] = $state['current_file_name'];
        }
        
        return [
            'status' => $status,
            'stage' => $state['stage'] ?? 'files',
            'progress' => $this->progress($state),
            'message' => $this->getMessage($state),
            'stats' => $stats,
            'state' => $state,
        ];
    }
    
    private function getMessage(array $state): string
    {
        if (($state['stage'] ?? '') === 'done') {
            $password = $state['options']['password'] ?? '';
            if ($password !== '') {
                return 'Export complete. Archive is password-protected.';
            }
            return 'Export complete.';
        }
        
        // Show current file if writing a large one
        if (!empty($state['current_file_name'])) {
            return 'Exporting: ' . basename($state['current_file_name']) . '...';
        }
        
        return 'Exporting files...';
    }

    private function progress(array $state): int
    {
        if (($state['stage'] ?? '') === 'done') {
            return 100;
        }

        $offset = (int) ($state['file_offset'] ?? 0);
        $increment = (int) floor($offset / 1000);

        return min(99, 50 + $increment);
    }

    private function appendManifest(string $manifestPath, string $relative, string $absolute): void
    {
        if ($manifestPath === '') {
            return;
        }

        $mode = @fileperms($absolute);
        $mtime = @filemtime($absolute);
        $size = @filesize($absolute);

        $entry = [
            'path' => $relative,
            'mode' => $mode !== false ? ($mode & 0777) : null,
            'mtime' => $mtime !== false ? $mtime : null,
            'size' => $size !== false ? $size : null,
        ];

        $line = json_encode($entry, JSON_UNESCAPED_SLASHES) . "\n";
        $this->filesystem->append($manifestPath, $line);
    }
}
