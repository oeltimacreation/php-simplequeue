<?php

/**
 * Redis Worker Example
 *
 * This example demonstrates running a background worker with Redis queue.
 * Run this script in a separate process to process jobs.
 *
 * Usage: php examples/redis-worker.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Oeltima\SimpleQueue\Contract\JobHandlerInterface;
use Oeltima\SimpleQueue\JobRegistry;
use Oeltima\SimpleQueue\QueueManager;
use Oeltima\SimpleQueue\Storage\PdoJobStorage;
use Oeltima\SimpleQueue\Worker;
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
        'name' => getenv('QUEUE_NAME') ?: 'default',
        'prefix' => getenv('QUEUE_PREFIX') ?: 'myapp',
    ],
];

// Example job handlers
class SendEmailJob implements JobHandlerInterface
{
    public function handle(int $jobId, array $payload, ?callable $progressCallback = null): mixed
    {
        $to = $payload['to'] ?? 'unknown';
        $subject = $payload['subject'] ?? 'No subject';

        echo "[Job #{$jobId}] Sending email to {$to}: {$subject}\n";

        if ($progressCallback) {
            $progressCallback(50, 'Preparing email...');
        }

        // Simulate email sending
        usleep(500000);

        if ($progressCallback) {
            $progressCallback(100, 'Email sent');
        }

        return ['sent_to' => $to, 'sent_at' => date('c')];
    }
}

class GenerateReportJob implements JobHandlerInterface
{
    public function handle(int $jobId, array $payload, ?callable $progressCallback = null): mixed
    {
        $reportType = $payload['type'] ?? 'unknown';
        $format = $payload['format'] ?? 'pdf';

        echo "[Job #{$jobId}] Generating {$reportType} report in {$format} format\n";

        $steps = 5;
        for ($i = 1; $i <= $steps; $i++) {
            if ($progressCallback) {
                $percent = (int) (($i / $steps) * 100);
                $progressCallback($percent, "Processing step {$i}/{$steps}");
            }
            usleep(200000); // Simulate work
        }

        return [
            'report_type' => $reportType,
            'format' => $format,
            'file' => "/reports/{$reportType}-" . date('Ymd') . ".{$format}",
            'generated_at' => date('c'),
        ];
    }
}

// Set up components
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

    // Job registry
    $registry = new JobRegistry();
    $registry->register('email.send', SendEmailJob::class);
    $registry->register('report.generate', GenerateReportJob::class);

    // Create worker
    $worker = new Worker(
        storage: $storage,
        queueManager: $queueManager,
        registry: $registry,
        queue: $config['queue']['name'],
        options: [
            'poll_timeout' => 5,
            'stuck_job_ttl' => 600,
            'lock_file' => '/tmp/myapp-worker.lock',
        ]
    );

    echo "Starting worker for queue '{$config['queue']['name']}'...\n";
    echo "Press Ctrl+C to stop\n\n";

    // Run the worker (blocks until shutdown signal)
    $worker->run();

} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
