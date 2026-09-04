<?php

declare(strict_types=1);

use Oeltima\SimpleQueue\Contract\JobHandlerInterface;
use Oeltima\SimpleQueue\Contract\JobStatus;
use Oeltima\SimpleQueue\Driver\InMemoryQueueDriver;
use Oeltima\SimpleQueue\JobDispatcher;
use Oeltima\SimpleQueue\JobRegistry;
use Oeltima\SimpleQueue\QueueManager;
use Oeltima\SimpleQueue\Storage\InMemoryJobStorage;
use Oeltima\SimpleQueue\Storage\PdoJobStorage;
use Oeltima\SimpleQueue\Worker;

$autoload = $argv[1] ?? null;
if (!is_string($autoload) || !is_file($autoload)) {
    throw new RuntimeException('Usage: consumer-smoke.php <consumer-vendor-autoload.php>');
}
require $autoload;

final class ConsumerSmokeHandler implements JobHandlerInterface
{
    /**
     * @param array<string, mixed> $payload
     * @return array{job_id: int, input: array<string, mixed>}
     */
    public function handle(int $jobId, array $payload, ?callable $progressCallback = null): array
    {
        return ['job_id' => $jobId, 'input' => $payload];
    }
}

$memoryStorage = new InMemoryJobStorage();
$memoryDriver = new InMemoryQueueDriver();
$memoryManager = new QueueManager($memoryDriver);
$registry = new JobRegistry();
$registry->register('consumer.smoke', ConsumerSmokeHandler::class);
$memoryJobId = (new JobDispatcher($memoryStorage, $memoryManager))->dispatch(
    'consumer.smoke',
    ['backend' => 'memory']
);
$worker = new Worker(
    $memoryStorage,
    $memoryManager,
    $registry,
    queue: 'default',
    options: ['lock_file' => null]
);
if (!$worker->processOne() || $memoryStorage->find($memoryJobId)?->status !== JobStatus::Completed) {
    throw new RuntimeException('In-memory consumer lifecycle failed');
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec(<<<'SQL'
CREATE TABLE background_jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    queue TEXT NOT NULL DEFAULT 'default',
    type TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending',
    payload TEXT,
    attempts INTEGER NOT NULL DEFAULT 0,
    max_attempts INTEGER NOT NULL DEFAULT 3,
    progress INTEGER DEFAULT NULL,
    progress_message TEXT DEFAULT NULL,
    result TEXT DEFAULT NULL,
    available_at TEXT NOT NULL,
    started_at TEXT DEFAULT NULL,
    completed_at TEXT DEFAULT NULL,
    locked_by TEXT DEFAULT NULL,
    locked_at TEXT DEFAULT NULL,
    lease_token TEXT DEFAULT NULL,
    error_message TEXT DEFAULT NULL,
    error_trace TEXT DEFAULT NULL,
    request_id TEXT DEFAULT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
)
SQL);
$pdoStorage = new PdoJobStorage($pdo);
$pdoJobId = $pdoStorage->createJob('consumer.pdo', ['backend' => 'sqlite']);
$claim = $pdoStorage->claimById($pdoJobId, 'consumer-worker');
if ($claim === null || !$pdoStorage->markCompleted($claim, ['ok' => true])) {
    throw new RuntimeException('PDO consumer lifecycle failed');
}
$completed = $pdoStorage->find($pdoJobId);
if ($completed?->status !== JobStatus::Completed || $completed->result !== ['ok' => true]) {
    throw new RuntimeException('PDO consumer result did not round-trip');
}

echo "Isolated no-dev consumer smoke passed\n";
