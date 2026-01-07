<?php

/**
 * Dispatch Jobs Example
 *
 * This example demonstrates dispatching jobs to the queue.
 * Jobs will be processed by the worker (redis-worker.php).
 *
 * Usage: php examples/dispatch-jobs.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Oeltima\SimpleQueue\JobDispatcher;
use Oeltima\SimpleQueue\QueueManager;
use Oeltima\SimpleQueue\Storage\PdoJobStorage;
use Predis\Client as RedisClient;

// Configuration
$config = [
    'redis' => [
        'host' => getenv('REDIS_HOST') ?: '127.0.0.1',
        'port' => (int) (getenv('REDIS_PORT') ?: 6379),
    ],
    'database' => [
        'dsn' => getenv('DATABASE_DSN') ?: 'mysql:host=localhost;dbname=myapp',
        'user' => getenv('DATABASE_USER') ?: 'root',
        'password' => getenv('DATABASE_PASSWORD') ?: '',
    ],
    'queue' => [
        'prefix' => getenv('QUEUE_PREFIX') ?: 'myapp',
    ],
];

try {
    // Database storage
    $pdo = new PDO(
        $config['database']['dsn'],
        $config['database']['user'],
        $config['database']['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $storage = new PdoJobStorage($pdo);

    // Redis queue
    $redis = new RedisClient([
        'host' => $config['redis']['host'],
        'port' => $config['redis']['port'],
    ]);
    $queueManager = QueueManager::redis($redis, $config['queue']['prefix']);

    // Create dispatcher
    $dispatcher = new JobDispatcher($storage, $queueManager);

    // Dispatch some jobs
    echo "Dispatching jobs...\n\n";

    // Single email job
    $jobId1 = $dispatcher->dispatch('email.send', [
        'to' => 'user@example.com',
        'subject' => 'Welcome to our service!',
        'body' => 'Thank you for signing up.',
    ]);
    echo "Dispatched email job #{$jobId1}\n";

    // Report generation job
    $jobId2 = $dispatcher->dispatch('report.generate', [
        'type' => 'monthly-sales',
        'format' => 'pdf',
        'month' => date('Y-m'),
    ]);
    echo "Dispatched report job #{$jobId2}\n";

    // Batch email jobs
    $emailPayloads = [
        ['to' => 'user1@example.com', 'subject' => 'Newsletter'],
        ['to' => 'user2@example.com', 'subject' => 'Newsletter'],
        ['to' => 'user3@example.com', 'subject' => 'Newsletter'],
    ];
    $batchIds = $dispatcher->dispatchBatch('email.send', $emailPayloads);
    echo "Dispatched " . count($batchIds) . " batch email jobs: " . implode(', ', $batchIds) . "\n";

    // Job with custom options
    $jobId3 = $dispatcher->dispatch(
        type: 'report.generate',
        payload: ['type' => 'yearly-summary', 'format' => 'xlsx'],
        queue: 'reports',      // Different queue
        maxAttempts: 5,        // More retry attempts
        requestId: 'req-' . uniqid()  // Correlation ID
    );
    echo "Dispatched custom options job #{$jobId3} to 'reports' queue\n";

    echo "\nJobs dispatched successfully!\n";
    echo "Run 'php examples/redis-worker.php' to process them.\n";

} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
