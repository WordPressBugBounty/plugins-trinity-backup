<?php

declare(strict_types=1);

namespace TrinityBackup\Engine;

if (!\defined('ABSPATH')) {
    exit;
}

use TrinityBackup\Core\StateManager;
use TrinityBackup\Engine\Steps\ExportDatabase;
use TrinityBackup\Engine\Steps\ExportFiles;
use TrinityBackup\Filesystem\FilesystemInterface;
use TrinityBackup\Archiver\TrinityCompressor;

final class Pipeline
{
    private const DEFAULT_TIME_LIMIT = 20;
    private const DEFAULT_DB_CHUNK = 200;
    private const DEFAULT_FILE_LIMIT = 100;

    private StateManager $stateManager;
    private FilesystemInterface $filesystem;
    private ExportDatabase $dbStep;
    private ExportFiles $filesStep;

    public function __construct(
        StateManager $stateManager,
        FilesystemInterface $filesystem,
        ExportDatabase $dbStep,
        ExportFiles $filesStep
    ) {
        $this->stateManager = $stateManager;
        $this->filesystem = $filesystem;
        $this->dbStep = $dbStep;
        $this->filesStep = $filesStep;
    }

    public function start(array $options = []): array
    {
        if (!empty($options['password']) && !TrinityCompressor::isEncryptionSupported()) {
            return [
                'status' => 'error',
                'message' => 'Encryption is not supported on this server (AES-256-GCM cipher unavailable).',
            ];
        }

        $origin = isset($options['origin']) ? (string) $options['origin'] : 'manual';
        if (!in_array($origin, ['manual', 'scheduled', 'pre_update'], true)) {
            $origin = 'manual';
        }

        $jobId = $this->stateManager->create();
        $uploads = wp_upload_dir();
        $baseDir = trailingslashit($uploads['basedir']) . 'trinity-backup/' . $jobId;
        $this->filesystem->ensureDir($baseDir);

        // Write simple metadata to identify how this backup was created.
        // Used for UI labeling and scheduled-retention policy.
        $metaPath = $baseDir . '/meta.json';
        $meta = [
            'origin' => $origin,
            'created_at' => time(),
        ];

        if ($origin === 'pre_update' && !empty($options['pre_update']) && is_array($options['pre_update'])) {
            $meta['pre_update'] = $options['pre_update'];
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing to uploads.
        @file_put_contents($metaPath, wp_json_encode($meta), LOCK_EX);

        $dbPath = $baseDir . '/database.sql';
        $manifestPath = $baseDir . '/manifest.jsonl';
        $archivePath = $baseDir . '/' . $jobId . '.trinity';
        
        // Build exclude directories based on options
        $excludeDirs = [trailingslashit($uploads['basedir']) . 'trinity-backup'];
        
        if (!empty($options['no_media'])) {
            $excludeDirs[] = trailingslashit($uploads['basedir']);
        }
        if (!empty($options['no_plugins'])) {
            $excludeDirs[] = WP_PLUGIN_DIR;
        }
        if (!empty($options['no_themes'])) {
            $excludeDirs[] = get_theme_root();
        }
        
        // Skip database if requested
        $skipDatabase = !empty($options['no_database']);
        $tables = $skipDatabase ? [] : $this->dbStep->listTables();

        $state = [
            'job_id' => $jobId,
            'job_type' => 'export',
            'stage' => $skipDatabase ? 'files' : 'db',
            'options' => $options,
            'origin' => $origin,
            'tables' => $tables,
            'table_index' => 0,
            'row_offset' => 0,
            'tables_started' => [],
            'db_header_written' => false,
            'db_footer_written' => false,
            'db_path' => $dbPath,
            'archive_path' => $archivePath,
            'manifest_path' => $manifestPath,
            'db_added' => $skipDatabase,
            'manifest_added' => false,
            'file_root' => WP_CONTENT_DIR,
            'file_offset' => 0,
            'exclude_dirs' => $excludeDirs,
            'stats' => [
                'rows' => 0,
                'files' => 0,
            ],
            'started_at' => time(),
        ];

        $this->stateManager->save($jobId, $state);

        return [
            'status' => 'continue',
            'job_id' => $jobId,
            'stage' => $state['stage'],
            'progress' => 0,
            'message' => 'Export started.',
        ];
    }

    public function run(string $jobId): array
    {
        $state = $this->stateManager->load($jobId);
        if ($state === null) {
            return [
                'status' => 'error',
                'message' => 'Job not found.',
            ];
        }

        $timeLimit = $this->getTimeLimit();
        $response = [];

        switch ($state['stage']) {
            case 'db':
                $response = $this->dbStep->run($state, $timeLimit, $this->getDbChunkSize());
                break;
            case 'files':
                $response = $this->filesStep->run($state, $timeLimit, $this->getFileLimit());
                break;
            case 'done':
                $response = $this->buildDoneResponse($state);
                break;
            default:
                $response = [
                    'status' => 'error',
                    'message' => 'Unknown stage.',
                ];
        }

        if (isset($response['state']) && is_array($response['state'])) {
            $state = $response['state'];
            unset($response['state']);
        }

        $this->stateManager->save($jobId, $state);

        if ($state['stage'] === 'done') {
            $response = $this->buildDoneResponse($state);
        }

        return $response;
    }

    private function buildDoneResponse(array $state): array
    {
        $uploads = wp_upload_dir();
        $downloadUrl = trailingslashit($uploads['baseurl']) . 'trinity-backup/' . $state['job_id'] . '/' . $state['job_id'] . '.trinity';

        return [
            'status' => 'done',
            'stage' => 'done',
            'progress' => 100,
            'download_url' => $downloadUrl,
            'message' => 'Export complete.',
            'stats' => $state['stats'],
        ];
    }

    private function getTimeLimit(): int
    {
        $value = (int) get_option('trinity_backup_time_limit', self::DEFAULT_TIME_LIMIT);
        if ($value < 5) {
            return 5;
        }

        return $value;
    }

    private function getDbChunkSize(): int
    {
        $value = (int) get_option('trinity_backup_db_chunk', self::DEFAULT_DB_CHUNK);
        if ($value < 50) {
            return 50;
        }

        return $value;
    }

    private function getFileLimit(): int
    {
        $value = (int) get_option('trinity_backup_file_limit', self::DEFAULT_FILE_LIMIT);
        if ($value < 20) {
            return 20;
        }

        return $value;
    }
}
