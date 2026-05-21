<?php

declare(strict_types=1);

namespace TrinityBackup\Core;

if (!\defined('ABSPATH')) {
    exit;
}

final class StateManager
{
    private const OPTION_PREFIX = 'trinity_backup_state_';
    private const OPTION_CURRENT = 'trinity_backup_current_job';

    public function create(): string
    {
        $jobId = $this->generateBackupName();
        $this->directSave(self::OPTION_CURRENT, $jobId);
        StorageSecurity::deleteLegacyPublicFiles();

        return $jobId;
    }

    /**
     * Generate a backup name in style: domain-YYYYMMDD-HHMMSS-random
     * Example: devtrinitybackup-local-20260113-123251-arfl3vsrff6q
     */
    public function generateBackupName(): string
    {
        // Get domain from site URL (strip protocol and path)
        $siteUrl = site_url();
        $parsed = wp_parse_url($siteUrl);
        $host = $parsed['host'] ?? 'backup';
        
        // Clean domain: remove www., convert dots/special chars to hyphens
        $domain = preg_replace('/^www\./', '', $host);
        $domain = preg_replace('/[^a-z0-9]+/i', '-', $domain);
        $domain = strtolower(trim($domain, '-'));
        
        if ($domain === '' || $domain === 'localhost') {
            $domain = 'backup';
        }
        
        // Date and time
        $date = gmdate('Ymd');
        $time = gmdate('His');
        
        // Random suffix (12 chars, lowercase alphanumeric)
        $random = $this->generateRandomSuffix(12);
        
        return sprintf('%s-%s-%s-%s', $domain, $date, $time, $random);
    }

    /**
     * Generate random lowercase alphanumeric suffix.
     */
    private function generateRandomSuffix(int $length): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $result = '';
        $bytes = random_bytes($length);
        
        for ($i = 0; $i < $length; $i++) {
            $result .= $chars[ord($bytes[$i]) % 36];
        }
        
        return $result;
    }

    public function save(string $jobId, array $state): void
    {
        // For import jobs, also save to file (survives database replacement)
        if (($state['job_type'] ?? '') === 'import') {
            $this->saveToFile($jobId, $state);
        }
        $this->directSave(self::OPTION_PREFIX . $jobId, $state);
    }

    public function load(string $jobId): ?array
    {
        // First try database
        $state = $this->directLoad(self::OPTION_PREFIX . $jobId);
        if (is_array($state)) {
            return $state;
        }
        
        // Fallback to file (for import jobs after database was replaced)
        return $this->loadFromFile($jobId);
    }

    public function getCurrentJobId(): ?string
    {
        $jobId = $this->directLoad(self::OPTION_CURRENT);
        if (is_string($jobId) && $jobId !== '') {
            return $jobId;
        }

        return null;
    }

    public function loadCurrent(): ?array
    {
        $jobId = $this->getCurrentJobId();
        if ($jobId === null) {
            return null;
        }

        return $this->load($jobId);
    }

    public function forget(string $jobId): void
    {
        $this->directDelete(self::OPTION_PREFIX . $jobId);
        $this->directDelete(self::OPTION_CURRENT);
        $this->forgetFile($jobId);
    }

    /**
     * Direct database save bypassing WordPress object cache.
     * Critical during import when we execute DROP/INSERT statements
     * that can corrupt or invalidate the object cache.
     */
    private function directSave(string $optionName, mixed $value): void
    {
        global $wpdb;

        $serialized = maybe_serialize($value);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Must bypass cache during import operations
        $exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s",
                $optionName
            )
        );

        if ((int) $exists > 0) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Must bypass cache during import operations
            $wpdb->update(
                $wpdb->options,
                ['option_value' => $serialized],
                ['option_name' => $optionName],
                ['%s'],
                ['%s']
            );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Must bypass cache during import operations
            $wpdb->insert(
                $wpdb->options,
                [
                    'option_name' => $optionName,
                    'option_value' => $serialized,
                    'autoload' => 'no',
                ],
                ['%s', '%s', '%s']
            );
        }

        // Also update WP cache to keep it consistent
        wp_cache_set($optionName, $value, 'options');
    }

    /**
     * Direct database load bypassing WordPress object cache.
     */
    private function directLoad(string $optionName): mixed
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Must bypass cache during import operations
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
                $optionName
            )
        );

        if ($row === null) {
            return null;
        }

        return maybe_unserialize($row->option_value);
    }

    /**
     * Direct database delete bypassing WordPress object cache.
     */
    private function directDelete(string $optionName): void
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Must bypass cache during import operations
        $wpdb->delete(
            $wpdb->options,
            ['option_name' => $optionName],
            ['%s']
        );

        wp_cache_delete($optionName, 'options');
    }
    
    /**
     * Get file path for state storage.
     */
    private function getStateFilePath(string $jobId): string
    {
        return StorageSecurity::getStateDir() . '/' . sanitize_file_name($jobId) . '.json';
    }
    
    /**
     * Save state to file (survives database replacement during import).
     */
    private function saveToFile(string $jobId, array $state): void
    {
        $path = $this->getStateFilePath($jobId);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Import state must survive database replacement between AJAX requests.
        file_put_contents($path, wp_json_encode($state, JSON_PRETTY_PRINT), LOCK_EX);
    }
    
    /**
     * Load state from file.
     */
    private function loadFromFile(string $jobId): ?array
    {
        $path = $this->getStateFilePath($jobId);

        $state = $this->readStateFile($path);
        if (is_array($state)) {
            return $state;
        }

        $legacyPath = StorageSecurity::getLegacyStateFilePath($jobId);
        $state = $this->readStateFile($legacyPath);
        if (is_array($state)) {
            $this->saveToFile($jobId, $state);
            wp_delete_file($legacyPath);
            return $state;
        }

        return null;
    }

    private function readStateFile(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading plugin-owned import state.
        $content = file_get_contents($path);
        if ($content === false) {
            return null;
        }

        $state = json_decode($content, true);
        return is_array($state) ? $state : null;
    }
    
    /**
     * Delete state file.
     */
    public function forgetFile(string $jobId): void
    {
        $path = $this->getStateFilePath($jobId);
        if (is_file($path)) {
            wp_delete_file($path);
        }

        $legacyPath = StorageSecurity::getLegacyStateFilePath($jobId);
        if (is_file($legacyPath)) {
            wp_delete_file($legacyPath);
        }
    }
}
