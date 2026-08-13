<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Oeltima\SimpleQueue\AdminManager;
use Oeltima\SimpleQueue\Contract\JobHandlerInterface;
use Oeltima\SimpleQueue\Driver\InMemoryQueueDriver;
use Oeltima\SimpleQueue\JobDispatcher;
use Oeltima\SimpleQueue\JobRegistry;
use Oeltima\SimpleQueue\QueueManager;
use Oeltima\SimpleQueue\Storage\InMemoryJobStorage;
use Oeltima\SimpleQueue\Worker;

final class FailedJobExampleHandler implements JobHandlerInterface
{
    public function handle(int $jobId, array $payload, ?callable $progressCallback = null): never
    {
        throw new RuntimeException('Example handler failure');
    }
}

$storage = new InMemoryJobStorage();
$queues = new QueueManager(new InMemoryQueueDriver());
$registry = new JobRegistry();
$registry->register('example.failure', FailedJobExampleHandler::class);
$dispatcher = new JobDispatcher($storage, $queues);
$jobId = $dispatcher->dispatch('example.failure', [], maxAttempts: 1);

$worker = new Worker($storage, $queues, $registry, options: [
    'lock_file' => null,
    'poll_timeout' => 0,
]);
$worker->processOne();

$admin = new AdminManager($storage, $queues);
echo 'Failed jobs: ' . count($admin->listFailed()) . PHP_EOL;
$admin->requeueFailed($jobId);
echo 'Re-queued job #' . $jobId . ': ' . $storage->find($jobId)?->status->value . PHP_EOL;
