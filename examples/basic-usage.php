<?php

/**
 * Basic Usage Example
 *
 * This example demonstrates the basic usage of PHP SimpleQueue
 * with in-memory storage (suitable for testing and development).
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Oeltima\SimpleQueue\Contract\JobHandlerInterface;
use Oeltima\SimpleQueue\Driver\InMemoryQueueDriver;
use Oeltima\SimpleQueue\JobDispatcher;
use Oeltima\SimpleQueue\JobRegistry;
use Oeltima\SimpleQueue\QueueManager;
use Oeltima\SimpleQueue\Storage\InMemoryJobStorage;
use Oeltima\SimpleQueue\Worker;

// 1. Define a job handler
class ExampleJob implements JobHandlerInterface
{
    public function handle(int $jobId, array $payload, ?callable $progressCallback = null): mixed
    {
        echo "Processing job #{$jobId}\n";

        $steps = 3;
        for ($i = 1; $i <= $steps; $i++) {
            if ($progressCallback) {
                $percent = (int) (($i / $steps) * 100);
                $progressCallback($percent, "Step {$i} of {$steps}");
            }
            usleep(100000); // Simulate work
        }

        return [
            'message' => $payload['message'] ?? 'No message',
            'completed_at' => date('Y-m-d H:i:s'),
        ];
    }
}

// 2. Set up components
$storage = new InMemoryJobStorage();
$driver = new InMemoryQueueDriver();
$queueManager = new QueueManager($driver);

// 3. Register job handlers
$registry = new JobRegistry();
$registry->register('example.job', ExampleJob::class);

// 4. Create dispatcher
$dispatcher = new JobDispatcher($storage, $queueManager);

// 5. Dispatch some jobs
echo "Dispatching jobs...\n";

$jobId1 = $dispatcher->dispatch('example.job', ['message' => 'Hello World']);
$jobId2 = $dispatcher->dispatch('example.job', ['message' => 'Second Job']);

echo "Dispatched job #$jobId1\n";
echo "Dispatched job #$jobId2\n";

// 6. Process jobs synchronously (for demo)
echo "\nProcessing jobs...\n";

$worker = new Worker(
    storage: $storage,
    queueManager: $queueManager,
    registry: $registry,
    queue: 'default',
    options: ['lock_file' => null] // Disable locking for demo
);

// Process each job
while ($worker->processOne()) {
    echo "Processed a job\n";
}

// 7. Check results
echo "\nJob results:\n";

$job1 = $dispatcher->getStatus($jobId1);
$job2 = $dispatcher->getStatus($jobId2);

echo "Job #$jobId1: {$job1->status}\n";
echo "Job #$jobId2: {$job2->status}\n";

if ($job1->result) {
    echo "Job #$jobId1 result: " . json_encode($job1->result) . "\n";
}
