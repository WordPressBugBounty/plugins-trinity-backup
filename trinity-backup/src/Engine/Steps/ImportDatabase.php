<?php
/**
 * Import database from SQL file.
 * 
 * Implementation:
 * - Reads SQL line by line
 * - Executes DROP TABLE, CREATE TABLE, INSERT statements directly
 * - Applies URL replacements in string values using proper serialization handling
 * 
 * @package TrinityBackup
 * 
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose
 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
 * 
 * Reason: Database import requires streaming file reads for large SQL files.
 */

declare(strict_types=1);

namespace TrinityBackup\Engine\Steps;

if (!\defined('ABSPATH')) {
    exit;
}

use RuntimeException;
use TrinityBackup\Database\DatabaseInterface;

final class ImportDatabase
{
    private DatabaseInterface $database;
    
    /** @var string[] */
    private array $searchPatterns = [];
    
    /** @var string[] */
    private array $replacePatterns = [];

    public function __construct(DatabaseInterface $database)
    {
        $this->database = $database;
    }

    /**
     * @param array<string, mixed> $state
     * @param array{search?: string[], replace?: string[]} $replacements URL replacements to apply during import
     * @return array<string, mixed>
     */
    public function run(array $state, int $timeLimit, array $replacements = []): array
    {
        $start = microtime(true);
        $dbPath = (string) $state['db_path'];
        $offset = (int) ($state['db_offset'] ?? 0);
        $partial = (string) ($state['db_partial'] ?? '');
        
        // Set up URL replacements
        $this->searchPatterns = $replacements['search'] ?? [];
        $this->replacePatterns = $replacements['replace'] ?? [];

        // Ensure backslash escapes are interpreted.
        // This prevents environments with NO_BACKSLASH_ESCAPES from storing literal backslashes.
        try {
            $this->database->execute("SET SESSION sql_mode = ''");
        } catch (\Throwable $e) {
            // Non-critical; continue.
        }

        $handle = fopen($dbPath, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Failed to open database file: ' . $dbPath);
        }

        if ($offset > 0 && fseek($handle, $offset) !== 0) {
            fclose($handle);
            throw new RuntimeException('Failed to seek database file.');
        }

        $executed = 0;
        $statement = $partial;
        $errors = [];

        $completed = false;
        while (!feof($handle) && (microtime(true) - $start) < $timeLimit) {
            $line = fgets($handle);
            if ($line === false) {
                break;
            }

            $trimmed = trim($line);
            
            // Skip comments and empty lines
            if ($trimmed === '' || str_starts_with($trimmed, '--') || str_starts_with($trimmed, '/*')) {
                continue;
            }

            // Handle multiple statements on one line (e.g., "...;INSERT INTO...")
            // by splitting on semicolons while respecting quoted strings
            $statements = $this->splitStatements($line);
            
            foreach ($statements as $idx => $part) {
                $isLast = ($idx === count($statements) - 1);
                
                $statement .= $part;
                
                // If this part ends with semicolon, it's a complete statement
                // (except for the last part which might be incomplete)
                if (str_ends_with(rtrim($part), ';')) {
                    $sql = trim($statement);
                    $statement = '';
                    
                    if ($sql === '') {
                        continue;
                    }
                    
                    // Skip transient queries
                    if ($this->shouldSkipQuery($sql)) {
                        continue;
                    }
                    
                    // Apply URL replacements to SQL values (when configured), and also
                    // repair WordPress placeholder-escaped percent tokens in legacy dumps.
                    if (!empty($this->searchPatterns) || strpos($sql, '{') !== false) {
                        $sql = $this->replaceInQuery($sql);
                    }
                    
                    // Execute the query
                    try {
                        $this->database->execute($sql);
                        $executed++;
                        $state['stats']['statements'] = ($state['stats']['statements'] ?? 0) + 1;
                    } catch (\Throwable $e) {
                        // Log error but continue (for non-critical errors)
                        $errors[] = $e->getMessage();
                        
                        // Stop on critical errors (table creation failures)
                        if (str_starts_with($sql, 'CREATE TABLE') || str_starts_with($sql, 'DROP TABLE')) {
                            fclose($handle);
                            throw new RuntimeException('Critical database error: ' . $e->getMessage());
                        }
                    }
                }
            }

            if ((microtime(true) - $start) >= $timeLimit) {
                break;
            }
        }

        if (feof($handle)) {
            $completed = true;
        }

        $offset = ftell($handle);
        fclose($handle);

        $state['db_offset'] = $offset;
        $state['db_partial'] = $statement;

        if ($completed) {
            // Run post-import cleanup
            $this->postImportCleanup();
            
            // Database is imported LAST - go to done
            $state['stage'] = 'done';
        }

        $result = [
            'status' => $completed ? 'done' : 'continue',
            'stage' => $state['stage'],
            'progress' => $completed ? 100 : $this->progress($dbPath, $offset),
            'message' => $completed ? 'Import complete!' : ($executed > 0 ? 'Importing database...' : 'Scanning database file...'),
            'stats' => $state['stats'],
            'state' => $state,
        ];
        
        // Pass URL change info to frontend (for redirect after import)
        if (!empty($state['url_changed'])) {
            $result['url_changed'] = true;
            $result['new_site_url'] = $state['new_site_url'] ?? '';
        }
        
        return $result;
    }
    
    /**
     * Split a line into multiple statements respecting quoted strings.
     * Returns array of statement parts - each ending with ';' except possibly the last.
     * @return string[]
     */
    private function splitStatements(string $line): array
    {
        $parts = [];
        $current = '';
        $inQuote = false;
        $escaped = false;
        $length = strlen($line);
        
        for ($i = 0; $i < $length; $i++) {
            $char = $line[$i];
            
            if ($escaped) {
                $current .= $char;
                $escaped = false;
                continue;
            }
            
            if ($char === '\\') {
                $current .= $char;
                $escaped = true;
                continue;
            }
            
            if ($char === "'" && !$inQuote) {
                $inQuote = true;
                $current .= $char;
                continue;
            }
            
            if ($char === "'" && $inQuote) {
                // Check for escaped quote ''
                if ($i + 1 < $length && $line[$i + 1] === "'") {
                    $current .= "''";
                    $i++;
                    continue;
                }
                $inQuote = false;
                $current .= $char;
                continue;
            }
            
            $current .= $char;
            
            // Statement separator found outside quotes
            if ($char === ';' && !$inQuote) {
                $parts[] = $current;
                $current = '';
            }
        }
        
        // Add remaining content (may not end with semicolon)
        if ($current !== '') {
            $parts[] = $current;
        }
        
        return $parts;
    }

    /**
     * Check if query should be skipped (transients, sessions, etc.)
     */
    private function shouldSkipQuery(string $sql): bool
    {
        // Skip transients
        if (strpos($sql, "'_transient_") !== false) {
            return true;
        }
        if (strpos($sql, "'_site_transient_") !== false) {
            return true;
        }
        
        // Skip WooCommerce sessions
        if (strpos($sql, "'_wc_session_") !== false) {
            return true;
        }
        
        return false;
    }

    /**
     * Apply URL replacements to SQL query.
     * Uses regex to find string values and applies serialization-aware replacement.
     */
    private function replaceInQuery(string $sql): string
    {
        // Quick check: do any search patterns exist in this query?
        $hasReplacementMatch = false;
        foreach ($this->searchPatterns as $pattern) {
            if (strpos($sql, $this->escapeForSql($pattern)) !== false) {
                $hasReplacementMatch = true;
                break;
            }
        }

        // Also check for WordPress placeholder escapes ("{[0-9a-f]{64}}") which can leak
        // into SQL dumps if esc_sql() was used during export on a different install.
        $hasPercentPlaceholder = (bool) preg_match('/\{[0-9a-f]{64}\}/i', $sql);

        if (!$hasReplacementMatch && !$hasPercentPlaceholder) {
            return $sql;
        }
        
        // Replace in all string values: '...'
        // This regex matches SQL string values including escaped quotes
        return preg_replace_callback(
            "/'((?:[^'\\\\]|\\\\.|'')*?)'/s",
            function ($matches) {
                return "'" . $this->normalizeValue($matches[1]) . "'";
            },
            $sql
        ) ?? $sql;
    }

    /**
     * Normalize a single SQL string literal payload.
     * - Repairs WordPress placeholder-escaped percent tokens from legacy exports.
     * - Applies URL replacements (serialization-aware) when configured.
     * Returns the original SQL-escaped payload unchanged when no changes are needed.
     */
    private function normalizeValue(string $value): string
    {
        $unescaped = $this->unescapeSql($value);
        $changed = false;

        // Restore placeholder-escaped percent tokens back to "%".
        // This pattern matches WPDB placeholder_escape() output: "{<64 hex>}".
        if (preg_match('/\{[0-9a-f]{64}\}/i', $unescaped)) {
            $restored = preg_replace('/\{[0-9a-f]{64}\}/i', '%', $unescaped);
            if (is_string($restored) && $restored !== $unescaped) {
                $unescaped = $restored;
                $changed = true;
            }
        }

        // Apply URL replacements if configured.
        if (!empty($this->searchPatterns)) {
            $hasMatch = false;
            foreach ($this->searchPatterns as $pattern) {
                if ($pattern !== '' && strpos($unescaped, $pattern) !== false) {
                    $hasMatch = true;
                    break;
                }
            }

            if ($hasMatch) {
                if ($this->isSerialized($unescaped)) {
                    $unescaped = $this->replaceInSerialized($unescaped);
                } else {
                    $unescaped = str_replace($this->searchPatterns, $this->replacePatterns, $unescaped);
                }

                $changed = true;
            }
        }

        if (!$changed) {
            return $value;
        }

        return $this->escapeSql($unescaped);
    }
    
    /**
     * Replace URLs in a single SQL value.
     * Handles serialized data properly.
     * Returns value unchanged if no replacements needed.
     */
    private function replaceInValue(string $value): string
    {
        // Unescape SQL string escapes
        $unescaped = $this->unescapeSql($value);

        // Optimization: only re-escape when a replacement actually applies.
        // This avoids accidental changes to strings containing backslashes for other reasons.
        $hasMatch = false;
        foreach ($this->searchPatterns as $pattern) {
            if ($pattern !== '' && strpos($unescaped, $pattern) !== false) {
                $hasMatch = true;
                break;
            }
        }

        if (!$hasMatch) {
            // Return original SQL-escaped value unchanged
            return $value;
        }
        
        // Check if this is serialized data
        if ($this->isSerialized($unescaped)) {
            $replaced = $this->replaceInSerialized($unescaped);
        } else {
            // Plain string - simple replacement
            $replaced = str_replace($this->searchPatterns, $this->replacePatterns, $unescaped);
        }
        
        // Re-escape for SQL
        return $this->escapeSql($replaced);
    }
    
    /**
     * Replace URLs in serialized data.
     * Parses serialized string and replaces values while maintaining correct string lengths.
     */
    private function replaceInSerialized(string $data): string
    {
        $pos = 0;
        $result = $this->parseAndReplaceSerialized($data, $pos);
        
        // If parsing failed or didn't consume entire string, use fallback
        if ($pos !== strlen($data) || $result === null) {
            // Fallback: simple replacement with length fixing
            $replaced = str_replace($this->searchPatterns, $this->replacePatterns, $data);
            return $this->fixSerializedLengths($replaced);
        }
        
        return $result;
    }
    
    /**
     * Parse serialized value and replace strings recursively.
     */
    private function parseAndReplaceSerialized(string $data, int &$pos): ?string
    {
        $length = strlen($data);
        if ($pos >= $length) {
            return null;
        }

        $type = $data[$pos];
        $pos++;

        switch ($type) {
            case 's': // String
                if (!isset($data[$pos]) || $data[$pos] !== ':') {
                    $pos--;
                    return null;
                }
                $pos++;
                
                $lenEnd = strpos($data, ':', $pos);
                if ($lenEnd === false) {
                    $pos--;
                    return null;
                }
                
                $strLength = (int) substr($data, $pos, $lenEnd - $pos);
                $pos = $lenEnd + 1;
                
                if (!isset($data[$pos]) || $data[$pos] !== '"') {
                    $pos--;
                    return null;
                }
                $pos++;
                
                $str = substr($data, $pos, $strLength);
                $pos += $strLength;
                
                if (!isset($data[$pos]) || $data[$pos] !== '"') {
                    $pos--;
                    return null;
                }
                $pos++;
                
                if (!isset($data[$pos]) || $data[$pos] !== ';') {
                    $pos--;
                    return null;
                }
                $pos++;
                
                // Try to parse nested serialized data
                $innerPos = 0;
                $innerResult = $this->parseAndReplaceSerialized($str, $innerPos);
                
                if ($innerPos === strlen($str) && $innerResult !== null) {
                    // String contained serialized data
                    $newStr = $innerResult;
                } else {
                    // Regular string - apply replacement
                    $newStr = str_replace($this->searchPatterns, $this->replacePatterns, $str);
                }
                
                return 's:' . strlen($newStr) . ':"' . $newStr . '";';

            case 'i': // Integer
            case 'd': // Double/Float
            case 'b': // Boolean
                if (!isset($data[$pos]) || $data[$pos] !== ':') {
                    $pos--;
                    return null;
                }
                $pos++;
                
                $end = strpos($data, ';', $pos);
                if ($end === false) {
                    $pos--;
                    return null;
                }
                
                $value = substr($data, $pos, $end - $pos);
                $pos = $end + 1;
                
                return $type . ':' . $value . ';';

            case 'N': // NULL
                if (!isset($data[$pos]) || $data[$pos] !== ';') {
                    $pos--;
                    return null;
                }
                $pos++;
                return 'N;';

            case 'a': // Array
                if (!isset($data[$pos]) || $data[$pos] !== ':') {
                    $pos--;
                    return null;
                }
                $pos++;
                
                $lenEnd = strpos($data, ':', $pos);
                if ($lenEnd === false) {
                    $pos--;
                    return null;
                }
                
                $arrayLength = (int) substr($data, $pos, $lenEnd - $pos);
                $pos = $lenEnd + 1;
                
                if (!isset($data[$pos]) || $data[$pos] !== '{') {
                    $pos--;
                    return null;
                }
                $pos++;
                
                $result = 'a:' . $arrayLength . ':{';
                
                for ($i = 0; $i < $arrayLength * 2; $i++) {
                    $element = $this->parseAndReplaceSerialized($data, $pos);
                    if ($element === null) {
                        $pos--;
                        return null;
                    }
                    $result .= $element;
                }
                
                if (!isset($data[$pos]) || $data[$pos] !== '}') {
                    $pos--;
                    return null;
                }
                $pos++;
                
                return $result . '}';

            case 'O': // Object
                if (!isset($data[$pos]) || $data[$pos] !== ':') {
                    $pos--;
                    return null;
                }
                $pos++;
                
                $classLenEnd = strpos($data, ':', $pos);
                if ($classLenEnd === false) {
                    $pos--;
                    return null;
                }
                
                $classLength = (int) substr($data, $pos, $classLenEnd - $pos);
                $pos = $classLenEnd + 1;
                
                if (!isset($data[$pos]) || $data[$pos] !== '"') {
                    $pos--;
                    return null;
                }
                $pos++;
                
                $className = substr($data, $pos, $classLength);
                $pos += $classLength;
                
                if (!isset($data[$pos]) || $data[$pos] !== '"') {
                    $pos--;
                    return null;
                }
                $pos++;
                
                if (!isset($data[$pos]) || $data[$pos] !== ':') {
                    $pos--;
                    return null;
                }
                $pos++;
                
                $propLenEnd = strpos($data, ':', $pos);
                if ($propLenEnd === false) {
                    $pos--;
                    return null;
                }
                
                $propCount = (int) substr($data, $pos, $propLenEnd - $pos);
                $pos = $propLenEnd + 1;
                
                if (!isset($data[$pos]) || $data[$pos] !== '{') {
                    $pos--;
                    return null;
                }
                $pos++;
                
                $result = 'O:' . $classLength . ':"' . $className . '":' . $propCount . ':{';
                
                for ($i = 0; $i < $propCount * 2; $i++) {
                    $element = $this->parseAndReplaceSerialized($data, $pos);
                    if ($element === null) {
                        $pos--;
                        return null;
                    }
                    $result .= $element;
                }
                
                if (!isset($data[$pos]) || $data[$pos] !== '}') {
                    $pos--;
                    return null;
                }
                $pos++;
                
                return $result . '}';

            default:
                $pos--;
                return null;
        }
    }
    
    /**
     * Check if a string is serialized PHP data
     */
    private function isSerialized(string $value): bool
    {
        if ($value === 'N;' || $value === 'b:0;' || $value === 'b:1;') {
            return true;
        }
        
        if (strlen($value) < 4) {
            return false;
        }
        
        if ($value[1] !== ':') {
            return false;
        }
        
        $first = $value[0];
        return in_array($first, ['s', 'a', 'O', 'i', 'd', 'b'], true);
    }
    
    /**
     * Fix serialized string lengths after simple replacement.
     * Fallback for when parsing fails.
     */
    private function fixSerializedLengths(string $value): string
    {
        return preg_replace_callback(
            '/s:(\d+):"((?:[^"\\\\]|\\\\.)*)";/s',
            function ($matches) {
                $content = $matches[2];
                $length = strlen($content);
                return 's:' . $length . ':"' . $content . '";';
            },
            $value
        ) ?? $value;
    }
    
    /**
     * Unescape SQL string value (MySQL format)
     */
    private function unescapeSql(string $value): string
    {
        // Handle doubled single quotes (SQL standard escape for ')
        $value = str_replace("''", "'", $value);
        
        // Handle MySQL backslash escapes using strtr for atomic replacement
        // Order doesn't matter with strtr - it replaces longest match first
        return strtr($value, [
            '\\0' => "\x00",   // NULL byte
            '\\n' => "\n",     // newline
            '\\r' => "\r",     // carriage return
            '\\\\' => '\\',    // backslash
            "\\'" => "'",      // single quote
            '\\"' => '"',      // double quote
            '\\Z' => "\x1a",   // Ctrl+Z (Windows EOF)
            '\\t' => "\t",     // tab
        ]);
    }
    
    /**
     * Escape string for SQL (MySQL format)
     */
    private function escapeSql(string $value): string
    {
        // Escape special characters for MySQL using strtr for atomic replacement
        return strtr($value, [
            "\x00" => '\\0',   // NULL byte
            "\n" => '\\n',     // newline
            "\r" => '\\r',     // carriage return
            '\\' => '\\\\',    // backslash (must be first conceptually, but strtr handles it)
            "'" => "\\'",      // single quote
            '"' => '\\"',      // double quote
            "\x1a" => '\\Z',   // Ctrl+Z (Windows EOF)
            "\t" => '\\t',     // tab
        ]);
    }
    
    /**
     * Escape search pattern for checking in SQL (where it's already escaped)
     */
    private function escapeForSql(string $value): string
    {
        return addslashes($value);
    }

    private function progress(string $dbPath, int $offset): int
    {
        $size = @filesize($dbPath);
        if ($size === false || $size === 0) {
            return 75;
        }

        // Database progress: 70-99% (files were 5-70%)
        $ratio = min(1, $offset / $size);
        return 70 + (int) round($ratio * 29);
    }
    
    /**
     * Post-import cleanup:
     * - Deactivate problematic plugins that require specific server extensions
     * - Deactivate SSL plugins if site is not HTTPS
     * - Deactivate login-hiding plugins to prevent lockout
     * - Remove drop-in files to prevent cache conflicts
     */
    private function postImportCleanup(): void
    {
        global $wpdb;
        
        // Cache plugins that require specific server extensions (Redis, Memcached, etc.)
        $cachePlugins = [
            'object-cache-pro/object-cache-pro.php',
            'redis-cache/redis-cache.php',
            'wp-redis/wp-redis.php',
            'memcached/memcached.php',
            'w3-total-cache/w3-total-cache.php',
            'litespeed-cache/litespeed-cache.php',
            'sg-cachepress/sg-cachepress.php',
        ];
        
        // SSL/HTTPS plugins - deactivate if current site is not HTTPS
        // These can cause redirect loops or break site access on HTTP environments
        $sslPlugins = [];
        if (!is_ssl()) {
            $sslPlugins = [
                'really-simple-ssl/rlrsssl-really-simple-ssl.php',
                'wordpress-https/wordpress-https.php',
                'wp-force-ssl/wp-force-ssl.php',
                'force-https-littlebizzy/force-https.php',
            ];
        }
        
        // Login-hiding/security plugins that can lock users out after migration
        $securityPlugins = [
            'wps-hide-login/wps-hide-login.php',
            'hide-my-wp/index.php',
            'hide-my-wordpress/index.php',
            'lockdown-wp-admin/lockdown-wp-admin.php',
            'rename-wp-login/rename-wp-login.php',
            'invisible-recaptcha/invisible-recaptcha.php',
            'wp-simple-firewall/icwp-wpsf.php',
        ];
        
        // Combine all problematic plugins
        $problematicPlugins = array_merge($cachePlugins, $sslPlugins, $securityPlugins);
        
        try {
            // Get current active plugins from the freshly imported database
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $activePlugins = $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT option_value FROM %i WHERE option_name = %s',
                    $wpdb->options,
                    'active_plugins'
                )
            );
            
            if ($activePlugins) {
                $plugins = @unserialize($activePlugins);
                if (is_array($plugins)) {
                    $originalCount = count($plugins);
                    
                    // Remove problematic plugins
                    $plugins = array_filter($plugins, function($plugin) use ($problematicPlugins) {
                        return !in_array($plugin, $problematicPlugins, true);
                    });
                    
                    // Re-index array and update if changed
                    if (count($plugins) !== $originalCount) {
                        $plugins = array_values($plugins);
                        $serialized = serialize($plugins);
                        
                        $wpdb->query(
                            $wpdb->prepare(
                                'UPDATE %i SET option_value = %s WHERE option_name = %s',
                                $wpdb->options,
                                $serialized,
                                'active_plugins'
                            )
                        );
                    }
                }
            }
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        } catch (\Throwable $e) {
            // Non-critical, continue
        }
        
        // Remove object-cache.php drop-in FIRST (before wp_cache_flush)
        // This prevents fatal errors from Redis/Memcached extensions not being available
        $objectCachePath = WP_CONTENT_DIR . '/object-cache.php';
        if (file_exists($objectCachePath)) {
            wp_delete_file($objectCachePath);
        }
        
        // Also remove advanced-cache.php drop-in (can cause issues with some cache plugins)
        $advancedCachePath = WP_CONTENT_DIR . '/advanced-cache.php';
        if (file_exists($advancedCachePath)) {
            wp_delete_file($advancedCachePath);
        }
        
        // Clear any cached data AFTER removing drop-ins
        // Use try-catch because cache backends may not be available
        try {
            if (function_exists('wp_cache_flush')) {
                wp_cache_flush();
            }
        } catch (\Throwable $e) {
            // Cache flush failed, not critical
        }
    }
}
