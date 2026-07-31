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

final class PrintScheduledMessage implements JobHandlerInterface
{
    public function handle(int $jobId, array $payload, ?callable $progress = null): mixed
    {
        $message = (string) ($payload['message'] ?? 'No message');
        echo "Job #{$jobId}: {$message}\n";

        return ['message' => $message];
    }
}

$storage = new InMemoryJobStorage();
$queues = new QueueManager(new InMemoryQueueDriver());
$registry = new JobRegistry();
$registry->register('message.scheduled', PrintScheduledMessage::class);
$dispatcher = new JobDispatcher($storage, $queues);
$worker = new Worker($storage, $queues, $registry, options: ['lock_file' => null]);

// 1. dispatchAfter() schedules first availability in the future.
$delaySeconds = 2;
$jobId = $dispatcher->dispatchAfter(
    $delaySeconds,
    'message.scheduled',
    ['message' => 'Hello from the future!']
);
echo "Dispatched job #{$jobId}; first availability in {$delaySeconds}s.\n";

// 2. Before the availability time the job is not claimable.
$processed = $worker->processOne();
$job = $dispatcher->getStatus($jobId);
echo sprintf(
    "Immediate processOne(): processed=%s, status=%s (not claimable yet)\n",
    var_export($processed, true),
    $job?->status->value
);

// 3. Wait until the job is due, then process it.
echo "Waiting {$delaySeconds}s for the job to become due...\n";
sleep($delaySeconds + 1);

$processed = $worker->processOne();
$job = $dispatcher->getStatus($jobId);
echo sprintf(
    "Due processOne(): processed=%s, status=%s, result=%s\n",
    var_export($processed, true),
    $job?->status->value,
    json_encode($job?->result, JSON_THROW_ON_ERROR)
);

// 4. dispatchAt() accepts an absolute Unix timestamp (or a DateTimeInterface).
$atId = $dispatcher->dispatchAt(time() + 1, 'message.scheduled', ['message' => 'Absolute timestamp!']);
echo "Dispatched job #{$atId} via dispatchAt() with a 1s absolute timestamp.\n";
sleep(2);
$worker->processOne();
$job = $dispatcher->getStatus($atId);
echo "dispatchAt() result status: {$job?->status->value}\n";

// dispatch() and dispatchBatch() accept the same optional parameter:
// $dispatcher->dispatch('message.scheduled', ['message' => 'later'], availableAt: time() + 10);
