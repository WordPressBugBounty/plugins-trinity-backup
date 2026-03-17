<?php
/**
 * Base archiver class for .trinity format.
 * 
 * @package TrinityBackup
 * 
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose
 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
 * 
 * Reason: Streaming archive operations require direct file handles.
 */

declare(strict_types=1);

namespace TrinityBackup\Archiver;

if (!\defined('ABSPATH')) {
    exit;
}

use RuntimeException;

/**
 * Base archiver class for .trinity format.
 * 
 * Block format (4377 bytes total):
 * - name:  255 bytes (filename without path)
 * - size:   14 bytes (file size as string)
 * - mtime:  12 bytes (modification timestamp)
 * - path: 4096 bytes (directory path)
 * 
 * EOF marker: 4377 null bytes
 */
abstract class TrinityArchiver
{
    protected const BLOCK_SIZE = 4377;
    
    protected const FORMAT_NAME = 'a255';   // filename
    protected const FORMAT_SIZE = 'a14';    // file size
    protected const FORMAT_MTIME = 'a12';   // modification time
    protected const FORMAT_PATH = 'a4096';  // path
    
    protected const CHUNK_SIZE = 512 * 1024; // 512KB chunks for reading/writing
    
    protected string $filePath;
    
    /** @var resource|null */
    protected $handle = null;
    
    public function __construct(string $filePath)
    {
        $this->filePath = $filePath;
    }
    
    /**
     * Get current file pointer position.
     */
    public function getFilePointer(): int
    {
        if ($this->handle === null) {
            throw new RuntimeException('Archive not open.');
        }
        
        $pos = ftell($this->handle);
        if ($pos === false) {
            throw new RuntimeException('Failed to get file pointer.');
        }
        
        return $pos;
    }
    
    /**
     * Set file pointer position.
     */
    public function setFilePointer(int $offset): void
    {
        if ($this->handle === null) {
            throw new RuntimeException('Archive not open.');
        }
        
        if (fseek($this->handle, $offset, SEEK_SET) === -1) {
            throw new RuntimeException('Failed to seek to offset: ' . $offset);
        }
    }
    
    /**
     * Close the archive.
     */
    public function close(): void
    {
        if ($this->handle !== null) {
            fclose($this->handle);
            $this->handle = null;
        }
    }
    
    /**
     * Pack a header block for a file.
     */
    protected function packHeader(string $name, int $size, int $mtime, string $path): string
    {
        $format = self::FORMAT_NAME . self::FORMAT_SIZE . self::FORMAT_MTIME . self::FORMAT_PATH;
        return pack($format, $name, (string) $size, (string) $mtime, $path);
    }
    
    /**
     * Unpack a header block.
     * 
     * @return array{name: string, size: int, mtime: int, path: string}|null
     */
    protected function unpackHeader(string $block): ?array
    {
        if (strlen($block) !== self::BLOCK_SIZE) {
            return null;
        }
        
        // Check for EOF (all nulls)
        if ($block === str_repeat("\0", self::BLOCK_SIZE)) {
            return null;
        }
        
        $format = self::FORMAT_NAME . 'name/' . self::FORMAT_SIZE . 'size/' . self::FORMAT_MTIME . 'mtime/' . self::FORMAT_PATH . 'path';
        $data = unpack($format, $block);
        
        if ($data === false) {
            return null;
        }
        
        return [
            'name' => rtrim($data['name'], "\0"),
            'size' => (int) rtrim($data['size'], "\0"),
            'mtime' => (int) rtrim($data['mtime'], "\0"),
            'path' => rtrim($data['path'], "\0"),
        ];
    }
    
    /**
     * Get EOF block (all nulls).
     */
    protected function getEofBlock(): string
    {
        return str_repeat("\0", self::BLOCK_SIZE);
    }
    
    public function __destruct()
    {
        $this->close();
    }
}
