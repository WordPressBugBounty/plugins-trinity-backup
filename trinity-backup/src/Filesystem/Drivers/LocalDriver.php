<?php
/**
 * Local filesystem driver.
 * 
 * @package TrinityBackup
 * 
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fread
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
 * 
 * Reason: Backup/restore operations require direct file handles for
 * streaming large files and file locking functionality.
 */

declare(strict_types=1);

namespace TrinityBackup\Filesystem\Drivers;

if (!\defined('ABSPATH')) {
    exit;
}

use RuntimeException;
use TrinityBackup\Filesystem\FilesystemInterface;

final class LocalDriver implements FilesystemInterface
{
    public function ensureDir(string $path): void
    {
        if (is_dir($path)) {
            return;
        }

        if (!mkdir($path, 0755, true) && !is_dir($path)) {
            throw new RuntimeException('Failed to create directory: ' . $path);
        }
    }

    public function append(string $path, string $data): void
    {
        $handle = fopen($path, 'ab');
        if ($handle === false) {
            throw new RuntimeException('Failed to open file for write: ' . $path);
        }

        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            throw new RuntimeException('Failed to lock file: ' . $path);
        }

        fwrite($handle, $data);
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    public function getIterator(string $root, array $excludeDirs): \Iterator
    {
        $rootPath = rtrim($root, "/\\");
        if ($rootPath === '' || !is_dir($rootPath)) {
            return new \ArrayIterator([]);
        }

        $excludeDirs = $this->normalizeExcludeDirs($excludeDirs);

        $directoryIterator = new \RecursiveDirectoryIterator(
            $rootPath,
            \FilesystemIterator::SKIP_DOTS
        );

        $filter = new \RecursiveCallbackFilterIterator(
            $directoryIterator,
            function (\SplFileInfo $current) use ($excludeDirs): bool {
                if ($current->isLink()) {
                    return false;
                }

                $path = $this->normalizePath($current->getPathname());
                if ($this->isExcludedNormalized($path, $excludeDirs)) {
                    return false;
                }

                if ($current->isDir()) {
                    return true;
                }

                return $current->isFile();
            }
        );

        $iterator = new \RecursiveIteratorIterator(
            $filter,
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        return (function () use ($iterator): \Generator {
            foreach ($iterator as $fileInfo) {
                if ($fileInfo instanceof \SplFileInfo && $fileInfo->isFile()) {
                    yield $fileInfo->getPathname();
                }
            }
        })();
    }

    public function readStream(string $path, int $chunkSize, int $offset = 0): \Iterator
    {
        if ($chunkSize < 1) {
            throw new RuntimeException('Chunk size must be a positive integer.');
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Failed to open file for read: ' . $path);
        }

        if (!flock($handle, LOCK_SH)) {
            fclose($handle);
            throw new RuntimeException('Failed to lock file for read: ' . $path);
        }

        return (function () use ($handle, $chunkSize, $offset): \Generator {
            try {
                if ($offset > 0 && fseek($handle, $offset) !== 0) {
                    throw new RuntimeException('Failed to seek file: ' . $offset);
                }

                while (!feof($handle)) {
                    $data = fread($handle, $chunkSize);
                    if ($data === '' || $data === false) {
                        break;
                    }
                    yield $data;
                }
            } finally {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        })();
    }

    public function listFiles(string $root, int $offset, int $limit, array $excludeDirs): array
    {
        $files = [];
        if ($limit < 1) {
            return $files;
        }

        $count = 0;
        $iterator = $this->getIterator($root, $excludeDirs);

        foreach ($iterator as $path) {
            if ($count < $offset) {
                $count++;
                continue;
            }

            $files[] = $path;
            $count++;

            if (count($files) >= $limit) {
                break;
            }
        }

        return $files;
    }

    /** @return string[] */
    private function normalizeExcludeDirs(array $excludeDirs): array
    {
        $normalized = [];
        foreach ($excludeDirs as $exclude) {
            $excludePath = rtrim($this->normalizePath((string) $exclude), '/') . '/';
            if ($excludePath !== '/') {
                $normalized[] = $excludePath;
            }
        }

        return $normalized;
    }

    private function isExcludedNormalized(string $path, array $excludeDirs): bool
    {
        $path = $this->normalizePath($path);
        $path = $path . '/';

        foreach ($excludeDirs as $exclude) {
            if (str_starts_with($path, $exclude)) {
                return true;
            }
        }

        return false;
    }

    private function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        if ($path === '/') {
            return $path;
        }

        return rtrim($path, '/');
    }
}
