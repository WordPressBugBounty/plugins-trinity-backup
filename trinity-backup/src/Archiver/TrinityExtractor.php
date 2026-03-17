<?php
/**
 * Extractor for reading .trinity archives.
 * 
 * @package TrinityBackup
 * 
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fread
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_touch
 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
 * 
 * Reason: Streaming archive extraction requires direct file handle operations.
 * WP_Filesystem doesn't support streaming binary reads/writes needed for
 * large archive files and resumable operations.
 */

declare(strict_types=1);

namespace TrinityBackup\Archiver;

if (!\defined('ABSPATH')) {
    exit;
}

use RuntimeException;

/**
 * Extractor for reading .trinity archives.
 * Supports streaming reads and resumable operations.
 * 
 * Decryption approach (AES-256-GCM):
 * - Each chunk is decrypted individually
 * - Encrypted chunk format: [4-byte cipher length][12-byte IV][16-byte tag][cipher data]
 * - Reads length prefix to know how much encrypted data to read
 */
final class TrinityExtractor extends TrinityArchiver
{
    private ?string $decryptionPassword = null;

     private const GCM_CIPHER = 'aes-256-gcm';
     private const GCM_IV_LEN = 12;
     private const GCM_TAG_LEN = 16;
    
    /** @var array{name: string, size: int, mtime: int, path: string}|null */
    private ?array $currentHeader = null;
    
    /**
     * Open the archive for reading.
     */
    public function open(): void
    {
        if (!is_readable($this->filePath)) {
            throw new RuntimeException('Archive not readable: ' . $this->filePath);
        }
        
        $this->handle = fopen($this->filePath, 'rb');
        if ($this->handle === false) {
            throw new RuntimeException('Failed to open archive for reading: ' . $this->filePath);
        }
    }
    
    /**
     * Set decryption password.
     */
    public function setPassword(string $password): self
    {
        $this->decryptionPassword = $password;
        return $this;
    }

    public static function isDecryptionSupported(): bool
    {
        if (!\extension_loaded('openssl')) {
            return false;
        }
        if (!\function_exists('openssl_decrypt') || !\function_exists('openssl_get_cipher_methods')) {
            return false;
        }

        $methods = \openssl_get_cipher_methods();
        if (!\is_array($methods)) {
            return false;
        }

        $methods = \array_map('strtolower', $methods);
        return \in_array(self::GCM_CIPHER, $methods, true);
    }

    private function assertDecryptionSupported(): void
    {
        if (!self::isDecryptionSupported()) {
            throw new RuntimeException('Server does not support AES-256-GCM decryption (OpenSSL missing or cipher unavailable).');
        }
    }
    
    /**
     * Check if password is set.
     */
    public function hasPassword(): bool
    {
        return $this->decryptionPassword !== null;
    }
    
    /**
     * Get total size of the archive.
     */
    public function getArchiveSize(): int
    {
        return (int) filesize($this->filePath);
    }
    
    /**
     * Read and extract the next file from archive.
     * 
     * @param string $destDir Destination directory
     * @param int $bytesRead Output: bytes read this call
     * @param int $fileOffset Input/Output: offset within current file (for resuming large files)
     * @param int $timeLimit Max seconds to spend
     * @param array<string> $skipEntries List of file names/paths to skip (not extract)
     * 
     * @return array{done: bool, file: string|null, eof: bool, skipped: bool}
     */
    public function extractNext(
        string $destDir,
        int &$bytesRead = 0,
        int &$fileOffset = 0,
        int $timeLimit = 10,
        array $skipEntries = []
    ): array {
        $bytesRead = 0;
        $start = microtime(true);
        
        if ($this->handle === null) {
            throw new RuntimeException('Archive not open.');
        }
        
        // Read header if at start of a new file
        if ($fileOffset === 0) {
            $headerBlock = fread($this->handle, self::BLOCK_SIZE);
            if ($headerBlock === false || strlen($headerBlock) < self::BLOCK_SIZE) {
                return ['done' => true, 'file' => null, 'eof' => true, 'skipped' => false];
            }
            
            $header = $this->unpackHeader($headerBlock);
            if ($header === null) {
                return ['done' => true, 'file' => null, 'eof' => true, 'skipped' => false];
            }
            
            $this->currentHeader = $header;
        }
        
        $header = $this->currentHeader ?? null;
        if ($header === null) {
            return ['done' => true, 'file' => null, 'eof' => true, 'skipped' => false];
        }
        
        // Build destination path
        $relativePath = $header['path'] !== '' 
            ? $header['path'] . '/' . $header['name']
            : $header['name'];
        
        // Check if this entry should be skipped (before creating file)
        if ($this->shouldSkipExtraction($header['name'], $relativePath, $skipEntries)) {
            // Skip file content without extracting
            $this->skipFileContent($header['size']);
            $this->currentHeader = null;
            return ['done' => true, 'file' => $relativePath, 'eof' => false, 'skipped' => true];
        }
        
        $destPath = rtrim($destDir, '/') . '/' . $relativePath;
        $destDirPath = dirname($destPath);
        
        if (!is_dir($destDirPath)) {
            wp_mkdir_p($destDirPath);
        }
        
        // Open destination file
        $mode = ($fileOffset > 0) ? 'ab' : 'wb';
        $destHandle = fopen($destPath, $mode);
        if ($destHandle === false) {
            throw new RuntimeException('Failed to create file: ' . $destPath);
        }
        
        try {
            $fileSize = $header['size'];
            $remaining = $fileSize - $fileOffset;
            
            // Read and write file content
            while ($remaining > 0) {
                // Read chunk (decrypt if password is set)
                if ($this->decryptionPassword !== null) {
                    // Read encrypted chunk: length prefix + IV + data
                    $actualRead = 0;
                    $chunk = $this->readEncryptedChunk($remaining, $actualRead);
                    $bytesRead += $actualRead;
                    $fileOffset += $actualRead;
                    $remaining -= $actualRead;
                } else {
                    // Read plain chunk
                    $chunkSize = min(self::CHUNK_SIZE, $remaining);
                    $chunk = fread($this->handle, $chunkSize);
                    
                    if ($chunk === false || $chunk === '') {
                        break;
                    }
                    
                    $readLen = strlen($chunk);
                    $bytesRead += $readLen;
                    $fileOffset += $readLen;
                    $remaining -= $readLen;
                }
                
                if ($chunk !== '') {
                    fwrite($destHandle, $chunk);
                }
                
                // Check timeout
                if ((microtime(true) - $start) >= $timeLimit) {
                    return ['done' => false, 'file' => $relativePath, 'eof' => false, 'skipped' => false];
                }
            }
            
            // Set file modification time
            if ($header['mtime'] > 0) {
                @touch($destPath, $header['mtime']);
            }
            
            $this->currentHeader = null;
            return ['done' => true, 'file' => $relativePath, 'eof' => false, 'skipped' => false];
            
        } finally {
            fclose($destHandle);
        }
    }
    
    /**
     * Check if a file should be skipped during extraction.
     * 
     * @param string $name File name only
     * @param string $relativePath Full relative path in archive
     * @param array<string> $skipEntries Entries to skip
     */
    private function shouldSkipExtraction(string $name, string $relativePath, array $skipEntries): bool
    {
        // Check against skip list - match by name or full path
        foreach ($skipEntries as $skip) {
            if ($name === $skip || $relativePath === $skip) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Skip file content without extracting.
     * Advances file pointer past the file's data.
     */
    private function skipFileContent(int $size): void
    {
        if ($this->handle === null || $size <= 0) {
            return;
        }
        
        if ($this->decryptionPassword !== null) {
            // For encrypted files, we need to read and discard chunks
            $remaining = $size;
            while ($remaining > 0) {
                $actualRead = 0;
                $this->readEncryptedChunk($remaining, $actualRead);
                if ($actualRead <= 0) {
                    break;
                }
                $remaining -= $actualRead;
            }
        } else {
            // For plain files, just seek past the content
            fseek($this->handle, $size, SEEK_CUR);
        }
    }
    
    /**
     * Read and decrypt one encrypted chunk.
     * 
     * @param int $maxBytes Maximum bytes to read from archive
     * @param int $actualRead Output: actual bytes read from archive
     * @return string Decrypted data
     */
    private function readEncryptedChunk(int $maxBytes, int &$actualRead): string
    {
        $actualRead = 0;

        $this->assertDecryptionSupported();
        
        // Read length prefix (4 bytes)
        $lengthData = fread($this->handle, 4);
        if ($lengthData === false || strlen($lengthData) < 4) {
            return '';
        }
        
        $unpacked = unpack('N', $lengthData);
        if ($unpacked === false) {
            return '';
        }
        
        $encryptedLen = $unpacked[1];
        
        // Sanity check
        if ($encryptedLen <= 0 || $encryptedLen > 10 * 1024 * 1024) {
            throw new RuntimeException('Invalid encrypted chunk length. Archive is corrupted or uses an unsupported encryption format.');
        }

        // Read IV (12 bytes)
        $iv = fread($this->handle, self::GCM_IV_LEN);
        if ($iv === false || strlen($iv) !== self::GCM_IV_LEN) {
            throw new RuntimeException('Failed to read IV.');
        }

        // Read auth tag (16 bytes)
        $tag = fread($this->handle, self::GCM_TAG_LEN);
        if ($tag === false || strlen($tag) !== self::GCM_TAG_LEN) {
            throw new RuntimeException('Failed to read auth tag.');
        }
        
        $encrypted = fread($this->handle, $encryptedLen);
        if ($encrypted === false || strlen($encrypted) !== $encryptedLen) {
            throw new RuntimeException('Failed to read encrypted data.');
        }

        $actualRead = 4 + self::GCM_IV_LEN + self::GCM_TAG_LEN + $encryptedLen;

        // Decrypt
        $key = hash('sha256', (string) $this->decryptionPassword, true);

        $decrypted = openssl_decrypt(
            $encrypted,
            self::GCM_CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            ''
        );
        
        if ($decrypted === false) {
            throw new RuntimeException('Decryption failed. Wrong password or corrupted archive?');
        }
        
        return $decrypted;
    }
    
    /**
     * List all files in the archive without extracting.
     * 
     * @return array<array{name: string, path: string, size: int, mtime: int}>
     */
    public function listFiles(): array
    {
        if ($this->handle === null) {
            $this->open();
        }
        
        $files = [];
        $savedPos = ftell($this->handle);
        
        fseek($this->handle, 0, SEEK_SET);
        
        while (!feof($this->handle)) {
            $headerBlock = fread($this->handle, self::BLOCK_SIZE);
            if ($headerBlock === false || strlen($headerBlock) < self::BLOCK_SIZE) {
                break;
            }
            
            $header = $this->unpackHeader($headerBlock);
            if ($header === null) {
                break;
            }
            
            $files[] = $header;
            
            // Skip file content
            fseek($this->handle, $header['size'], SEEK_CUR);
        }
        
        fseek($this->handle, $savedPos, SEEK_SET);
        
        return $files;
    }
    
    /**
     * Extract a specific file by name.
     */
    public function extractFile(string $archiveName, string $destPath): bool
    {
        if ($this->handle === null) {
            $this->open();
        }
        
        $savedPos = ftell($this->handle);
        fseek($this->handle, 0, SEEK_SET);
        
        while (!feof($this->handle)) {
            $headerBlock = fread($this->handle, self::BLOCK_SIZE);
            if ($headerBlock === false || strlen($headerBlock) < self::BLOCK_SIZE) {
                break;
            }
            
            $header = $this->unpackHeader($headerBlock);
            if ($header === null) {
                break;
            }
            
            $relativePath = $header['path'] !== '' 
                ? $header['path'] . '/' . $header['name']
                : $header['name'];
            
            if ($relativePath === $archiveName) {
                $destDir = dirname($destPath);
                if (!is_dir($destDir)) {
                    wp_mkdir_p($destDir);
                }
                
                $destHandle = fopen($destPath, 'wb');
                if ($destHandle === false) {
                    fseek($this->handle, $savedPos, SEEK_SET);
                    return false;
                }
                
                $remaining = $header['size'];
                while ($remaining > 0) {
                    if ($this->decryptionPassword !== null) {
                        $actualRead = 0;
                        $chunk = $this->readEncryptedChunk($remaining, $actualRead);
                        $remaining -= $actualRead;
                    } else {
                        $chunkSize = min(self::CHUNK_SIZE, $remaining);
                        $chunk = fread($this->handle, $chunkSize);
                        if ($chunk === false) {
                            break;
                        }
                        $remaining -= strlen($chunk);
                    }
                    
                    if ($chunk !== '') {
                        fwrite($destHandle, $chunk);
                    }
                }
                
                fclose($destHandle);
                
                if ($header['mtime'] > 0) {
                    @touch($destPath, $header['mtime']);
                }
                
                fseek($this->handle, $savedPos, SEEK_SET);
                return true;
            }
            
            // Skip this file's content
            fseek($this->handle, $header['size'], SEEK_CUR);
        }
        
        fseek($this->handle, $savedPos, SEEK_SET);
        return false;
    }
    
    /**
     * Get file content as string.
     */
    public function getFileContent(string $archiveName): ?string
    {
        if ($this->handle === null) {
            $this->open();
        }
        
        $savedPos = ftell($this->handle);
        fseek($this->handle, 0, SEEK_SET);
        
        while (!feof($this->handle)) {
            $headerBlock = fread($this->handle, self::BLOCK_SIZE);
            if ($headerBlock === false || strlen($headerBlock) < self::BLOCK_SIZE) {
                break;
            }
            
            $header = $this->unpackHeader($headerBlock);
            if ($header === null) {
                break;
            }
            
            $relativePath = $header['path'] !== '' 
                ? $header['path'] . '/' . $header['name']
                : $header['name'];
            
            if ($relativePath === $archiveName) {
                $content = '';
                $remaining = $header['size'];
                
                while ($remaining > 0) {
                    if ($this->decryptionPassword !== null) {
                        $actualRead = 0;
                        $chunk = $this->readEncryptedChunk($remaining, $actualRead);
                        $remaining -= $actualRead;
                    } else {
                        $chunkSize = min(self::CHUNK_SIZE, $remaining);
                        $chunk = fread($this->handle, $chunkSize);
                        if ($chunk === false) {
                            break;
                        }
                        $remaining -= strlen($chunk);
                    }
                    
                    $content .= $chunk;
                }
                
                fseek($this->handle, $savedPos, SEEK_SET);
                return $content;
            }
            
            // Skip this file's content
            fseek($this->handle, $header['size'], SEEK_CUR);
        }
        
        fseek($this->handle, $savedPos, SEEK_SET);
        return null;
    }
}
