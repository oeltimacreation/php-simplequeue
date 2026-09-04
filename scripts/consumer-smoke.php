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

function consumerAutoload(mixed $arguments): string
{
    if (!is_array($arguments)) {
        throw new RuntimeException('Usage: consumer-smoke.php <consumer-vendor-autoload.php>');
    }
    $autoload = $arguments[1] ?? null;
    if (!is_string($autoload) || !is_file($autoload)) {
        throw new RuntimeException('Usage: consumer-smoke.php <consumer-vendor-autoload.php>');
    }
    return $autoload;
}

require consumerAutoload($_SERVER['argv'] ?? null);

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

function assertInMemoryConsumerLifecycle(): void
{
    $storage = new InMemoryJobStorage();
    $manager = new QueueManager(new InMemoryQueueDriver());
    $registry = new JobRegistry();
    $registry->register('consumer.smoke', ConsumerSmokeHandler::class);
    $jobId = (new JobDispatcher($storage, $manager))->dispatch('consumer.smoke', ['backend' => 'memory']);
    $worker = new Worker($storage, $manager, $registry, queue: 'default', options: ['lock_file' => null]);
    if (!$worker->processOne()) {
        throw new RuntimeException('In-memory consumer did not process the queued job');
    }
    if ($storage->find($jobId)?->status !== JobStatus::Completed) {
        throw new RuntimeException('In-memory consumer lifecycle failed');
    }
}

function consumerPdo(): PDO
{
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
    return $pdo;
}

function assertPdoConsumerLifecycle(): void
{
    $storage = new PdoJobStorage(consumerPdo());
    $jobId = $storage->createJob('consumer.pdo', ['backend' => 'sqlite']);
    $claim = $storage->claimById($jobId, 'consumer-worker');
    if ($claim === null) {
        throw new RuntimeException('PDO consumer did not claim the queued job');
    }
    if (!$storage->markCompleted($claim, ['ok' => true])) {
        throw new RuntimeException('PDO consumer lifecycle failed');
    }
    $completed = $storage->find($jobId);
    if ($completed?->status !== JobStatus::Completed || $completed->result !== ['ok' => true]) {
        throw new RuntimeException('PDO consumer result did not round-trip');
    }
}

assertInMemoryConsumerLifecycle();
assertPdoConsumerLifecycle();
echo "Isolated no-dev consumer smoke passed\n";
