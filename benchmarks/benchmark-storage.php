<?php

declare(strict_types=1);

use Oeltima\SimpleQueue\Contract\ClockInterface;
use Oeltima\SimpleQueue\Storage\PdoJobStorage;

/** @return array{BenchmarkPdo, PdoJobStorage} */
function sqliteStorage(?ClockInterface $clock = null): array
{
    $pdo = new BenchmarkPdo('sqlite::memory:', options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    $pdo->exec(<<<'SQL'
CREATE TABLE background_jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    queue TEXT NOT NULL DEFAULT 'default', type TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending', payload TEXT,
    attempts INTEGER NOT NULL DEFAULT 0, max_attempts INTEGER NOT NULL DEFAULT 3,
    progress INTEGER, progress_message TEXT, result TEXT, available_at TEXT NOT NULL,
    started_at TEXT, completed_at TEXT, locked_by TEXT, locked_at TEXT, lease_token TEXT,
    error_message TEXT, error_trace TEXT, request_id TEXT,
    created_at TEXT NOT NULL, updated_at TEXT NOT NULL
);
CREATE INDEX idx_claim_ready ON background_jobs (queue, status, available_at, id);
CREATE INDEX idx_status_available ON background_jobs (status, available_at);
CREATE INDEX idx_locked_at ON background_jobs (locked_at);
CREATE UNIQUE INDEX uq_active_request_id ON background_jobs (request_id)
    WHERE status IN ('pending', 'running');
SQL);
    $pdo->resetCounts();
    return [$pdo, new PdoJobStorage($pdo, 'background_jobs', $clock)];
}

/** @return list<array{index: int, value: string}> */
function payloads(BenchmarkOptions $options): array
{
    $payloads = [];
    for ($index = 0; $index < $options->jobs; $index++) {
        $payloads[] = ['index' => $index, 'value' => 'benchmark'];
    }
    return $payloads;
}

/**
 * @param array{operations: int} $operation
 * @return array<string, int>
 */
function databaseCounts(BenchmarkPdo $pdo, array $operation): array
{
    return [
        'operations' => $operation['operations'],
        'db_queries' => $pdo->queries,
        'db_transactions' => $pdo->transactions,
    ];
}
