<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/handlers.php';

use Oeltima\SimpleQueue\JobRegistry;
use Oeltima\SimpleQueue\Worker;

try {
    [$storage, $queues, , $queue] = createExampleQueue();
    $registry = new JobRegistry();
    $registry->register('email.send', ExampleEmailHandler::class);
    $registry->register('report.generate', ExampleReportHandler::class);

    $worker = new Worker(
        storage: $storage,
        queueManager: $queues,
        registry: $registry,
        queue: $queue,
        options: ['lock_file' => "/tmp/simplequeue-example-{$queue}.lock"],
    );

    echo "Worker started for queue '{$queue}'. Press Ctrl+C to stop.\n";
    exit($worker->run());
} catch (Throwable $exception) {
    fwrite(STDERR, "Unable to start worker: {$exception->getMessage()}\n");
    exit(1);
}
