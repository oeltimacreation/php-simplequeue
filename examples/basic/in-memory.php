<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Oeltima\SimpleQueue\Contract\JobHandlerInterface;
use Oeltima\SimpleQueue\Driver\InMemoryQueueDriver;
use Oeltima\SimpleQueue\JobDispatcher;
use Oeltima\SimpleQueue\JobRegistry;
use Oeltima\SimpleQueue\QueueManager;
use Oeltima\SimpleQueue\Storage\InMemoryJobStorage;
use Oeltima\SimpleQueue\Worker;

final class PrintMessage implements JobHandlerInterface
{
    public function handle(int $jobId, array $payload, ?callable $progress = null): mixed
    {
        if ($progress !== null) {
            $progress(50, 'Printing message');
        }

        $message = (string) ($payload['message'] ?? 'No message');
        echo "Job #{$jobId}: {$message}\n";

        if ($progress !== null) {
            $progress(100, 'Done');
        }

        return ['message' => $message];
    }
}

$storage = new InMemoryJobStorage();
$queues = new QueueManager(new InMemoryQueueDriver());
$registry = new JobRegistry();
$registry->register('message.print', PrintMessage::class);
$dispatcher = new JobDispatcher($storage, $queues);

$jobId = $dispatcher->dispatch('message.print', ['message' => 'Hello, queue!']);
$worker = new Worker($storage, $queues, $registry, options: ['lock_file' => null]);
$worker->processOne();

$job = $dispatcher->getStatus($jobId);
echo "Status: {$job?->status->value}; result: " . json_encode($job?->result, JSON_THROW_ON_ERROR) . "\n";
