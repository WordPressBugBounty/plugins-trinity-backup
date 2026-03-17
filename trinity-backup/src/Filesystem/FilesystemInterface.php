<?php

declare(strict_types=1);

namespace TrinityBackup\Filesystem;

if (!\defined('ABSPATH')) {
    exit;
}

interface FilesystemInterface
{
    public function ensureDir(string $path): void;

    public function append(string $path, string $data): void;

    /** @return string[] */
    public function listFiles(string $root, int $offset, int $limit, array $excludeDirs): array;

    /** @return \Iterator<int, string> */
    public function getIterator(string $root, array $excludeDirs): \Iterator;

    /** @return \Iterator<int, string> */
    public function readStream(string $path, int $chunkSize, int $offset = 0): \Iterator;
}
