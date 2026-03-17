<?php

declare(strict_types=1);

namespace TrinityBackup\Database;

if (!\defined('ABSPATH')) {
    exit;
}

final class QueryBuilder
{
    /** @param array<string, mixed> $row */
    public function buildInsert(string $table, array $row, callable $escaper): string
    {
        $columns = [];
        $values = [];

        foreach ($row as $column => $value) {
            $columns[] = $this->escapeIdentifier((string) $column);
            $values[] = $this->formatValue($value, $escaper);
        }

        $columnList = implode(', ', $columns);
        $valueList = implode(', ', $values);

        // Use INSERT INTO - tables are dropped and recreated before import
        // so there are no conflicts with existing data
        return sprintf(
            "INSERT INTO %s (%s) VALUES (%s);\n",
            $this->escapeIdentifier($table),
            $columnList,
            $valueList
        );
    }

    private function formatValue(mixed $value, callable $escaper): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        $escaped = $escaper((string) $value);
        return "'" . $escaped . "'";
    }

    private function escapeIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
