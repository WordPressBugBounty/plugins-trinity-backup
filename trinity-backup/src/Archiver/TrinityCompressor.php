<?php
/**
 * Compressor for creating .trinity archives.
 * 
 * @package TrinityBackup
 * 
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fread
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose
 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
 * 
 * Reason: Streaming archive creation requires direct file handle operations.
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
 * Compressor for creating .trinity archives.
 * Supports streaming writes and resumable operations.
 * 
 * Encryption approach (AES-256-GCM):
 * - Each chunk is encrypted individually with its own IV
 * - Header is written with original file size, then updated with actual (encrypted) size after file is complete
 * - Encrypted chunk format: [4-byte cipher length][12-byte IV][16-byte tag][cipher data]
 */
final class TrinityCompressor extends TrinityArchiver
{
    private ?string $encryptionPassword = null;

    private const GCM_CIPHER = 'aes-256-gcm';
    private const GCM_IV_LEN = 12;
    private const GCM_TAG_LEN = 16;
    
    /** @var int Position where current file's header size field is located */
    private int $currentHeaderSizePosition = 0;
    
    /** @var int Total bytes written for current file (including encryption overhead) */
    private int $currentFileWritten = 0;
    
    /**
     * Open the archive for writing.
     */
    public function open(): void
    {
        $dir = dirname($this->filePath);
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        
        // Open for reading and writing, create if not exists
        $this->handle = fopen($this->filePath, 'c+b');
        if ($this->handle === false) {
            throw new RuntimeException('Failed to open archive for writing: ' . $this->filePath);
        }
        
        // Seek to end
        fseek($this->handle, 0, SEEK_END);
    }
    
    /**
     * Set encryption password.
     */
    public function setPassword(string $password): self
    {
        $this->encryptionPassword = $password;
        return $this;
    }

    public static function isEncryptionSupported(): bool
    {
        if (!\extension_loaded('openssl')) {
            return false;
        }
        if (!\function_exists('openssl_encrypt') || !\function_exists('openssl_get_cipher_methods')) {
            return false;
        }

        $methods = \openssl_get_cipher_methods();
        if (!\is_array($methods)) {
            return false;
        }

        $methods = \array_map('strtolower', $methods);
        return \in_array(self::GCM_CIPHER, $methods, true);
    }

    private function assertEncryptionSupported(): void
    {
        if (!self::isEncryptionSupported()) {
            throw new RuntimeException('Server does not support AES-256-GCM encryption (OpenSSL missing or cipher unavailable).');
        }
    }
    
    /**
     * Check if encryption is enabled.
     */
    public function isEncrypted(): bool
    {
        return $this->encryptionPassword !== null;
    }
    
    /**
     * Add a file to the archive.
     * 
     * @param string $filePath Absolute path to file
     * @param string $archiveName Relative path in archive
     * @param int $bytesWritten Output: bytes written this call
     * @param int $fileOffset Input/Output: offset within source file (for resuming)
     * @param int $timeLimit Max seconds to spend
     * 
     * @return bool True if file is completely written, false if needs continuation
     */
    public function addFile(
        string $filePath, 
        string $archiveName, 
        int &$bytesWritten = 0, 
        int &$fileOffset = 0,
        int $timeLimit = 10
    ): bool {
        $bytesWritten = 0;
        $start = microtime(true);
        
        if (!is_readable($filePath)) {
            return true;
        }
        
        $stat = @stat($filePath);
        if ($stat === false) {
            return true;
        }
        
        $fileHandle = fopen($filePath, 'rb');
        if ($fileHandle === false) {
            return true;
        }
        
        try {
            $originalFileSize = $stat['size'];
            $mtime = $stat['mtime'];
            
            $name = basename($archiveName);
            $path = dirname($archiveName);
            if ($path === '.') {
                $path = '';
            }
            
            // Write header at start of file
            if ($fileOffset === 0) {
                // Remember position of size field (after name: 255 bytes)
                $this->currentHeaderSizePosition = (int) ftell($this->handle) + 255;
                $this->currentFileWritten = 0;
                
                // Write header with original size (will be updated later if encrypted)
                $header = $this->packHeader($name, $originalFileSize, $mtime, $path);
                $written = fwrite($this->handle, $header);
                if ($written !== self::BLOCK_SIZE) {
                    throw new RuntimeException('Failed to write header for: ' . $archiveName);
                }
            }
            
            // Seek to position in source file
            if ($fileOffset > 0) {
                fseek($fileHandle, $fileOffset, SEEK_SET);
            }
            
            // Write file content in chunks
            while (!feof($fileHandle)) {
                $chunk = fread($fileHandle, self::CHUNK_SIZE);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                
                $sourceChunkLen = strlen($chunk);
                
                // Encrypt if password is set
                if ($this->encryptionPassword !== null) {
                    $chunk = $this->encryptChunk($chunk);
                }
                
                $chunkLen = strlen($chunk);
                if ($chunkLen > 0) {
                    $written = fwrite($this->handle, $chunk);
                    if ($written === false || $written !== $chunkLen) {
                        throw new RuntimeException('Failed to write file content: ' . $archiveName);
                    }
                    $bytesWritten += $written;
                    $this->currentFileWritten += $written;
                }
                
                // Update source file offset (original bytes read)
                $fileOffset += $sourceChunkLen;
                
                // Check timeout
                if ((microtime(true) - $start) >= $timeLimit) {
                    return false; // Not complete, needs continuation
                }
            }
            
            // File is complete - update header with actual size if encrypted
            if ($this->encryptionPassword !== null && $this->currentFileWritten !== $originalFileSize) {
                $this->updateHeaderSize($this->currentHeaderSizePosition, $this->currentFileWritten);
            }
            
            return true;
            
        } finally {
            fclose($fileHandle);
        }
    }
    
    /**
     * Add content from string.
     */
    public function addFromString(string $archiveName, string $content): void
    {
        $name = basename($archiveName);
        $path = dirname($archiveName);
        if ($path === '.') {
            $path = '';
        }
        
        $originalSize = strlen($content);
        $mtime = time();
        
        // Encrypt if password is set
        if ($this->encryptionPassword !== null && $content !== '') {
            $content = $this->encryptChunk($content);
        }
        
        $actualSize = strlen($content);
        
        // Write header with actual (possibly encrypted) size
        $header = $this->packHeader($name, $actualSize, $mtime, $path);
        $written = fwrite($this->handle, $header);
        if ($written !== self::BLOCK_SIZE) {
            throw new RuntimeException('Failed to write header for: ' . $archiveName);
        }
        
        // Write content
        if ($content !== '') {
            $written = fwrite($this->handle, $content);
            if ($written === false || $written !== strlen($content)) {
                throw new RuntimeException('Failed to write content for: ' . $archiveName);
            }
        }
    }
    
    /**
     * Update the size field in a file header.
     * Used after encryption when actual size differs from original.
     */
    private function updateHeaderSize(int $sizePosition, int $newSize): void
    {
        if ($this->handle === null) {
            return;
        }
        
        $currentPos = ftell($this->handle);
        
        // Seek to size field position
        fseek($this->handle, $sizePosition, SEEK_SET);
        
        // Write new size (14 bytes, null-padded)
        $sizeBlock = pack(self::FORMAT_SIZE, (string) $newSize);
        fwrite($this->handle, $sizeBlock);
        
        // Restore position
        fseek($this->handle, $currentPos, SEEK_SET);
    }
    
    /**
     * Truncate archive at current position.
     */
    public function truncate(): void
    {
        if ($this->handle === null) {
            return;
        }
        
        $pos = ftell($this->handle);
        if ($pos !== false) {
            ftruncate($this->handle, $pos);
        }
    }
    
    /**
     * Finalize the archive by writing EOF marker.
     */
    public function finalize(): void
    {
        if ($this->handle === null) {
            return;
        }
        
        fseek($this->handle, 0, SEEK_END);
        fwrite($this->handle, $this->getEofBlock());
        $this->truncate();
    }
    
    /**
     * Encrypt a chunk of data using AES-256-GCM.
     * Format: [4-byte cipher length][12-byte IV][16-byte tag][cipher data]
     */
    private function encryptChunk(string $data): string
    {
        if ($this->encryptionPassword === null || $data === '') {
            return $data;
        }

        $this->assertEncryptionSupported();
        
        // Generate random IV
        $iv = random_bytes(self::GCM_IV_LEN);
        
        // Derive 32-byte key from password
        $key = hash('sha256', $this->encryptionPassword, true);
        $tag = '';
        
        $encrypted = openssl_encrypt(
            $data,
            self::GCM_CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::GCM_TAG_LEN
        );
        
        if ($encrypted === false || strlen($tag) !== self::GCM_TAG_LEN) {
            throw new RuntimeException('Encryption failed (OpenSSL).');
        }
        
        // Return: length prefix + IV + tag + encrypted data
        return pack('N', strlen($encrypted)) . $iv . $tag . $encrypted;
    }
}
