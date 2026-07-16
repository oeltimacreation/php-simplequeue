<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Oeltima\SimpleQueue\JobDispatcher;
use Oeltima\SimpleQueue\QueueManager;
use Oeltima\SimpleQueue\Storage\PdoJobStorage;

$jobs = isset($argv[1]) ? filter_var($argv[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : 1000;
if ($jobs === false) {
    fwrite(STDERR, "Usage: php examples/benchmark/database.php [positive-job-count]\n");
    exit(1);
}

$pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec(<<<'SQL'
CREATE TABLE background_jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    queue TEXT NOT NULL, type TEXT NOT NULL, status TEXT NOT NULL,
    payload TEXT, attempts INTEGER NOT NULL, max_attempts INTEGER NOT NULL,
    progress INTEGER, progress_message TEXT, result TEXT, available_at TEXT NOT NULL,
    started_at TEXT, completed_at TEXT, locked_by TEXT, locked_at TEXT, lease_token TEXT,
    error_message TEXT, error_trace TEXT, request_id TEXT, created_at TEXT NOT NULL, updated_at TEXT NOT NULL
);
CREATE INDEX idx_claim_ready ON background_jobs (queue, status, available_at, id);
SQL);

$storage = new PdoJobStorage($pdo);
$dispatcher = new JobDispatcher($storage, QueueManager::database($storage));

$started = hrtime(true);
for ($i = 0; $i < $jobs; $i++) {
    $dispatcher->dispatch('benchmark.noop', ['index' => $i]);
}
$dispatchSeconds = (hrtime(true) - $started) / 1_000_000_000;

$started = hrtime(true);
$claimed = [];
for ($i = 0; $i < $jobs; $i++) {
    $claim = $storage->claimNextAvailable('default', 'benchmark-worker');
    if ($claim !== null) {
        $claimed[] = $claim;
    }
}
$claimSeconds = (hrtime(true) - $started) / 1_000_000_000;

$started = hrtime(true);
foreach ($claimed as $claim) {
    $storage->markCompleted($claim, ['result' => 'ok']);
}
$completeSeconds = (hrtime(true) - $started) / 1_000_000_000;

printf("SQLite in-memory benchmark (%d jobs)\n", $jobs);
printf("Dispatch: %.3fs (%.0f jobs/s)\n", $dispatchSeconds, $jobs / max($dispatchSeconds, 0.000001));
printf("Claim:    %.3fs (%.0f jobs/s)\n", $claimSeconds, count($claimed) / max($claimSeconds, 0.000001));
printf("Complete: %.3fs (%.0f jobs/s)\n", $completeSeconds, count($claimed) / max($completeSeconds, 0.000001));
