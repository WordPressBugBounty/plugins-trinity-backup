<?php
/**
 * Operation lock to prevent concurrent destructive operations.
 *
 * Stored in uploads/trinity-backup as a small JSON file so it survives
 * database replacement during restore (import).
 */

declare(strict_types=1);

namespace TrinityBackup\Core;

defined('ABSPATH') || exit;

final class OperationLock
{
    private const LOCK_FILE = '_operation_lock.json';

    /**
     * Acquire a global lock for an operation.
     *
     * @return array{ok:bool, token?:string, lock?:array, message?:string}
     */
    public function acquire(string $operation, string $owner, int $ttlSeconds, array $meta = []): array
    {
        $operation = $this->normalizeOperation($operation);
        $ttlSeconds = max(30, $ttlSeconds);

        $path = $this->getLockPath();
        $handle = @fopen($path, 'c+');
        if ($handle === false) {
            return [
                'ok' => false,
                'message' => 'Unable to open lock file.',
            ];
        }

        try {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_flock -- Need atomic lock across requests.
            if (!flock($handle, LOCK_EX)) {
                return [
                    'ok' => false,
                    'message' => 'Unable to lock operation file.',
                ];
            }

            $existing = $this->readFromHandle($handle);
            if (is_array($existing) && $this->isActive($existing)) {
                if (!$this->conflicts($operation, (string) ($existing['operation'] ?? ''))) {
                    // Non-conflicting ops are currently not supported; we keep a single global lock.
                    // Return as blocked anyway to keep behavior consistent and safe.
                }

                return [
                    'ok' => false,
                    'lock' => $existing,
                    'message' => $this->formatBlockedMessage($existing),
                ];
            }

            $token = $this->newToken();
            $now = time();
            $lock = [
                'token' => $token,
                'operation' => $operation,
                'owner' => $owner,
                'started_at' => $now,
                'expires_at' => $now + $ttlSeconds,
                'meta' => $meta,
            ];

            $this->writeToHandle($handle, $lock);

            return [
                'ok' => true,
                'token' => $token,
                'lock' => $lock,
            ];
        } finally {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_flock -- releasing lock
            @flock($handle, LOCK_UN);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing file
            fclose($handle);
        }
    }

    public function touch(string $token, int $ttlSeconds): bool
    {
        $ttlSeconds = max(30, $ttlSeconds);
        $path = $this->getLockPath();
        $handle = @fopen($path, 'c+');
        if ($handle === false) {
            return false;
        }

        try {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_flock -- Need atomic lock across requests.
            if (!flock($handle, LOCK_EX)) {
                return false;
            }

            $existing = $this->readFromHandle($handle);
            if (!is_array($existing) || (string) ($existing['token'] ?? '') !== $token) {
                return false;
            }

            $existing['expires_at'] = time() + $ttlSeconds;
            $this->writeToHandle($handle, $existing);
            return true;
        } finally {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_flock -- releasing lock
            @flock($handle, LOCK_UN);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing file
            fclose($handle);
        }
    }

    public function release(string $token): bool
    {
        $path = $this->getLockPath();
        $handle = @fopen($path, 'c+');
        if ($handle === false) {
            return false;
        }

        try {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_flock -- Need atomic lock across requests.
            if (!flock($handle, LOCK_EX)) {
                return false;
            }

            $existing = $this->readFromHandle($handle);
            if (!is_array($existing)) {
                return true;
            }

            if ((string) ($existing['token'] ?? '') !== $token) {
                return false;
            }

            // Best-effort cleanup.
            $this->truncateHandle($handle);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removing our own lock file.
            @unlink($path);
            return true;
        } finally {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_flock -- releasing lock
            @flock($handle, LOCK_UN);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing file
            fclose($handle);
        }
    }

    public function getActive(): ?array
    {
        $path = $this->getLockPath();
        if (!is_file($path)) {
            return null;
        }

        $handle = @fopen($path, 'r');
        if ($handle === false) {
            return null;
        }

        try {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_flock -- Shared lock for read
            @flock($handle, LOCK_SH);
            $existing = $this->readFromHandle($handle);
        } finally {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_flock -- releasing lock
            @flock($handle, LOCK_UN);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing file
            fclose($handle);
        }

        if (!is_array($existing) || !$this->isActive($existing)) {
            return null;
        }

        return $existing;
    }

    public function isBlocked(string $requestedOperation): ?array
    {
        $active = $this->getActive();
        if ($active === null) {
            return null;
        }

        if ($this->conflicts($this->normalizeOperation($requestedOperation), (string) ($active['operation'] ?? ''))) {
            return $active;
        }

        return null;
    }

    private function conflicts(string $requested, string $active): bool
    {
        $requested = $this->normalizeOperation($requested);
        $active = $this->normalizeOperation($active);

        if ($requested === '' || $active === '') {
            return true;
        }

        // Single global lock model: anything active blocks any other protected operation.
        // Kept explicit in case we later allow some non-conflicting operations.
        return $requested !== $active;
    }

    private function normalizeOperation(string $operation): string
    {
        $operation = strtolower(trim($operation));
        return preg_replace('/[^a-z0-9_\-]/', '', $operation) ?? '';
    }

    private function isActive(array $lock): bool
    {
        $expires = (int) ($lock['expires_at'] ?? 0);
        if ($expires <= 0) {
            return false;
        }

        return time() < $expires;
    }

    private function formatBlockedMessage(array $lock): string
    {
        $op = (string) ($lock['operation'] ?? 'operation');
        return sprintf('Another operation is currently running (%s). Please wait until it finishes.', $op);
    }

    private function getLockPath(): string
    {
        $uploads = wp_upload_dir();
        $dir = trailingslashit($uploads['basedir']) . 'trinity-backup';
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }

        return $dir . '/' . self::LOCK_FILE;
    }

    private function readFromHandle($handle): mixed
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fseek -- need to rewind
        @fseek($handle, 0);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- read small JSON file
        $raw = stream_get_contents($handle);
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function writeToHandle($handle, array $lock): void
    {
        $this->truncateHandle($handle);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fseek -- ensure start
        @fseek($handle, 0);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- write small JSON
        fwrite($handle, wp_json_encode($lock));
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fflush -- ensure persisted
        fflush($handle);
    }

    private function truncateHandle($handle): void
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_ftruncate -- truncate lock file
        @ftruncate($handle, 0);
    }

    private function newToken(): string
    {
        try {
            return bin2hex(random_bytes(16));
        } catch (\Throwable) {
            return uniqid('trinity_lock_', true);
        }
    }
}
