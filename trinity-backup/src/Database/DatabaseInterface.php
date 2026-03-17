<?php

declare(strict_types=1);

namespace TrinityBackup\Database;

if (!\defined('ABSPATH')) {
    exit;
}

interface DatabaseInterface
{
    public function connect(): void;

    /** @return string[] */
    public function listTables(): array;

    public function showCreateTable(string $table): string;

    /** @return array<int, array<string, mixed>> */
    public function select(string $sql): array;

    public function execute(string $sql): void;

    public function escape(string $value): string;
}
