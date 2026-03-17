<?php

declare(strict_types=1);

namespace TrinityBackup\Engine\Steps;

if (!\defined('ABSPATH')) {
    exit;
}

use TrinityBackup\Database\DatabaseInterface;
use TrinityBackup\Database\QueryBuilder;
use TrinityBackup\Filesystem\FilesystemInterface;

final class ExportDatabase
{
    private DatabaseInterface $database;
    private FilesystemInterface $filesystem;
    private QueryBuilder $builder;

    public function __construct(DatabaseInterface $database, FilesystemInterface $filesystem)
    {
        $this->database = $database;
        $this->filesystem = $filesystem;
        $this->builder = new QueryBuilder();
    }

    /** @return string[] */
    public function listTables(): array
    {
        return $this->database->listTables();
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    public function run(array $state, int $timeLimit, int $chunkSize): array
    {
        $start = microtime(true);
        $dbPath = (string) $state['db_path'];
        $options = (array) ($state['options'] ?? []);
        $noSpam = !empty($options['no_spam_comments']);

        if (empty($state['db_header_written'])) {
            $this->filesystem->append($dbPath, $this->header());
            $state['db_header_written'] = true;
        }

        $tables = $state['tables'];
        $tableIndex = (int) $state['table_index'];
        $rowOffset = (int) $state['row_offset'];
        $tablesStarted = (array) ($state['tables_started'] ?? []);

        global $wpdb;

        while ((microtime(true) - $start) < $timeLimit) {
            if ($tableIndex >= count($tables)) {
                if (empty($state['db_footer_written'])) {
                    $this->filesystem->append($dbPath, $this->footer());
                    $state['db_footer_written'] = true;
                }
                $state['stage'] = 'files';
                break;
            }

            $table = (string) $tables[$tableIndex];

            if (!isset($tablesStarted[$table])) {
                $create = $this->database->showCreateTable($table);
                $this->filesystem->append(
                    $dbPath,
                    sprintf("DROP TABLE IF EXISTS %s;\n%s;\n", $this->escapeIdentifier($table), $create)
                );
                $tablesStarted[$table] = true;
            }

            // Формируем SQL запрос с учётом фильтрации спама
            $sql = $this->buildSelectQuery($table, $rowOffset, $chunkSize, $noSpam, $wpdb);

            $rows = $this->database->select($sql);
            if (empty($rows)) {
                $tableIndex++;
                $rowOffset = 0;
                continue;
            }

            foreach ($rows as $row) {
                $insert = $this->builder->buildInsert($table, $row, [$this->database, 'escape']);
                $this->filesystem->append($dbPath, $insert);
                $rowOffset++;
                $state['stats']['rows']++;

                if ((microtime(true) - $start) >= $timeLimit) {
                    break 2;
                }
            }

            if (count($rows) < $chunkSize) {
                $tableIndex++;
                $rowOffset = 0;
            }
        }

        $state['table_index'] = $tableIndex;
        $state['row_offset'] = $rowOffset;
        $state['tables_started'] = $tablesStarted;

        // Add current table to stats for progress display
        $currentTable = $tableIndex < count($tables) ? $tables[$tableIndex] : ($tables[count($tables) - 1] ?? '');
        $state['stats']['current_table'] = $currentTable;

        return [
            'status' => 'continue',
            'stage' => $state['stage'],
            'progress' => $this->progress($tableIndex, count($tables)),
            'message' => 'Exporting database...',
            'stats' => $state['stats'],
            'state' => $state,
        ];
    }

    private function header(): string
    {
        return "-- Trinity Backup database export\n"
            . "SET SESSION sql_mode = '';\n"
            . "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n"
            . "SET FOREIGN_KEY_CHECKS=0;\n"
            . "START TRANSACTION;\n\n";
    }

    private function footer(): string
    {
        return "\nCOMMIT;\nSET FOREIGN_KEY_CHECKS=1;\n";
    }

    private function progress(int $tableIndex, int $totalTables): int
    {
        if ($totalTables === 0) {
            return 50;
        }

        $ratio = $tableIndex / $totalTables;
        return (int) max(1, min(50, round($ratio * 50)));
    }

    private function escapeIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    /**
     * Builds SELECT query with optional spam filtering
     */
    private function buildSelectQuery(string $table, int $offset, int $limit, bool $noSpam, object $wpdb): string
    {
        $escapedTable = str_replace('`', '``', $table);
        
        // Фильтрация спам-комментариев
        if ($noSpam && $table === $wpdb->comments) {
            return sprintf(
                "SELECT * FROM `%s` WHERE comment_approved != 'spam' LIMIT %d, %d",
                $escapedTable,
                $offset,
                $limit
            );
        }
        
        // Фильтрация метаданных спам-комментариев
        if ($noSpam && $table === $wpdb->commentmeta) {
            return sprintf(
                "SELECT cm.* FROM `%s` cm 
                 INNER JOIN `%s` c ON cm.comment_id = c.comment_ID 
                 WHERE c.comment_approved != 'spam' 
                 LIMIT %d, %d",
                $escapedTable,
                str_replace('`', '``', $wpdb->comments),
                $offset,
                $limit
            );
        }
        
        return sprintf(
            'SELECT * FROM `%s` LIMIT %d, %d',
            $escapedTable,
            $offset,
            $limit
        );
    }
}
