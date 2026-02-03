# OeltimaCreation PHP SimpleQueue

A lightweight, framework-agnostic background job queue system for PHP. Supports Redis and database-backed queues with automatic retries, progress tracking, and graceful shutdown.

## Features

- **Framework Agnostic**: Works with any PHP framework or standalone applications
- **Multiple Queue Drivers**: Redis (recommended) and Database polling
- **Automatic Retries**: Configurable retry with exponential backoff
- **Progress Tracking**: Report job progress with percentage and messages
- **Graceful Shutdown**: Handles SIGTERM/SIGINT for clean worker termination
- **Singleton Worker**: File locking prevents multiple workers from running
- **Stale Job Recovery**: Automatically recovers jobs stuck in running state
- **PSR Compliant**: Uses PSR-3 Logger and PSR-11 Container interfaces

## Requirements

- PHP 8.1 or higher
- Redis (optional, for Redis driver)
- PDO (optional, for database driver)

## Installation

```bash
composer require oeltimacreation/php-simplequeue
```

For Redis support:
```bash
composer require predis/predis
```

## Quick Start

### 1. Create a Job Handler

```php
<?php

use Oeltima\SimpleQueue\Contract\JobHandlerInterface;

class SendEmailJob implements JobHandlerInterface
{
    public function handle(int $jobId, array $payload, ?callable $progressCallback = null): mixed
    {
        $to = $payload['to'];
        $subject = $payload['subject'];
        $body = $payload['body'];

        // Report progress
        if ($progressCallback) {
            $progressCallback(50, 'Sending email...');
        }

        // Send email logic here
        mail($to, $subject, $body);

        if ($progressCallback) {
            $progressCallback(100, 'Email sent');
        }

        return ['sent_at' => date('Y-m-d H:i:s')];
    }
}
```

### 2. Set Up the Queue

```php
<?php

use Oeltima\SimpleQueue\JobDispatcher;
use Oeltima\SimpleQueue\JobRegistry;
use Oeltima\SimpleQueue\QueueManager;
use Oeltima\SimpleQueue\Storage\PdoJobStorage;
use Predis\Client as RedisClient;

// Create storage with connection factory (recommended for long-running workers)
$connectionFactory = fn() => new PDO(
    'mysql:host=localhost;dbname=myapp',
    'user',
    'password',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$storage = new PdoJobStorage($connectionFactory);

// Or pass a PDO instance directly (for short-lived scripts)
// $pdo = new PDO('mysql:host=localhost;dbname=myapp', 'user', 'password');
// $storage = new PdoJobStorage($pdo);

// Create queue manager with Redis (recommended)
$redis = new RedisClient(['host' => '127.0.0.1']);
$queueManager = QueueManager::redis($redis);

// Or use database polling as fallback
// $queueManager = QueueManager::database($storage);

// Or auto-select (tries Redis first, falls back to database)
// $queueManager = QueueManager::create('auto', $redis, $storage);

// Create job registry and register handlers
$registry = new JobRegistry();
$registry->register('email.send', SendEmailJob::class);

// Create dispatcher
$dispatcher = new JobDispatcher($storage, $queueManager);
```

### 3. Dispatch Jobs

```php
// Dispatch a single job
$jobId = $dispatcher->dispatch('email.send', [
    'to' => 'user@example.com',
    'subject' => 'Welcome!',
    'body' => 'Thanks for signing up.',
]);

// Dispatch with custom options
$jobId = $dispatcher->dispatch(
    type: 'email.send',
    payload: ['to' => 'user@example.com', 'subject' => 'Hello'],
    queue: 'emails',      // Custom queue name
    maxAttempts: 5,       // Retry up to 5 times
    requestId: 'req-123'  // Correlation ID for tracing
);

// Dispatch batch
$jobIds = $dispatcher->dispatchBatch('email.send', [
    ['to' => 'user1@example.com', 'subject' => 'Hello'],
    ['to' => 'user2@example.com', 'subject' => 'Hello'],
]);

// Check job status
$job = $dispatcher->getStatus($jobId);
echo $job->status;    // pending, running, completed, failed
echo $job->progress;  // 0-100
```

### 4. Run the Worker

```php
<?php
// worker.php

use Oeltima\SimpleQueue\Worker;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

// Set up logging
$logger = new Logger('worker');
$logger->pushHandler(new StreamHandler('php://stdout'));

// Create worker
$worker = new Worker(
    storage: $storage,
    queueManager: $queueManager,
    registry: $registry,
    logger: $logger,
    queue: 'default',
    options: [
        'poll_timeout' => 5,        // Seconds to wait for jobs
        'stuck_job_ttl' => 600,     // Recover jobs running > 10 min
        'retry_base_delay' => 2,    // Base delay for exponential backoff
        'retry_max_delay' => 300,   // Maximum retry delay (5 min)
        'lock_file' => '/tmp/myapp-worker.lock',
    ]
);

// Run the worker (blocks until shutdown signal)
$worker->run();
```

Run the worker:
```bash
php worker.php
```

For production, use a process manager like Supervisor:

```ini
[program:queue-worker]
command=php /path/to/worker.php
autostart=true
autorestart=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/log/queue-worker.log
```

## Database Schema

Create the jobs table:

```sql
CREATE TABLE background_jobs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    queue VARCHAR(255) NOT NULL DEFAULT 'default',
    type VARCHAR(255) NOT NULL,
    status ENUM('pending', 'running', 'completed', 'failed', 'cancelled') NOT NULL DEFAULT 'pending',
    payload JSON,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts INT UNSIGNED NOT NULL DEFAULT 3,
    progress INT UNSIGNED DEFAULT NULL,
    progress_message VARCHAR(255) DEFAULT NULL,
    result JSON DEFAULT NULL,
    available_at DATETIME DEFAULT NULL,
    started_at DATETIME DEFAULT NULL,
    completed_at DATETIME DEFAULT NULL,
    locked_by VARCHAR(255) DEFAULT NULL,
    locked_at DATETIME DEFAULT NULL,
    error_message TEXT DEFAULT NULL,
    error_trace TEXT DEFAULT NULL,
    request_id VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    
    INDEX idx_queue_status (queue, status),
    INDEX idx_status_available (status, available_at),
    INDEX idx_locked_at (locked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

## Configuration

### Queue Drivers

**Redis Driver** (recommended for production):
```php
use Oeltima\SimpleQueue\QueueManager;
use Predis\Client;

$redis = new Client(['host' => '127.0.0.1', 'port' => 6379]);
$queueManager = QueueManager::redis($redis, 'myapp'); // prefix for Redis keys
```

**Database Driver** (fallback option):
```php
$queueManager = QueueManager::database($storage);
```

**Auto Selection**:
```php
$queueManager = QueueManager::create(
    driverName: 'auto',     // 'redis', 'db', or 'auto'
    redis: $redis,          // Optional Redis client
    storage: $storage,      // Optional storage for DB fallback
    redisPrefix: 'myapp'
);
```

### Worker Options

| Option | Default | Description |
|--------|---------|-------------|
| `poll_timeout` | 5 | Seconds to wait for new jobs |
| `stuck_job_ttl` | 600 | Seconds before recovering stuck jobs |
| `retry_base_delay` | 2 | Base delay for exponential backoff |
| `retry_max_delay` | 300 | Maximum retry delay in seconds |
| `lock_file` | `/tmp/simplequeue-worker.lock` | Lock file path (null to disable) |

### PSR-11 Container Integration

```php
use Oeltima\SimpleQueue\JobRegistry;

// Pass your PSR-11 container
$registry = new JobRegistry($container);
$registry->register('email.send', SendEmailJob::class);

// Handler will be resolved from container if registered
// Otherwise, instantiated directly
```

## Advanced Usage

### Connection Handling for Long-Running Workers

When running workers for extended periods, database connections can time out (e.g., MySQL's `wait_timeout`). To handle this gracefully, pass a **connection factory** instead of a PDO instance:

```php
use Oeltima\SimpleQueue\Storage\PdoJobStorage;

// Connection factory - creates fresh connections on demand
$connectionFactory = function (): PDO {
    $pdo = new PDO(
        'mysql:host=localhost;dbname=myapp',
        'user',
        'password',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    return $pdo;
};

$storage = new PdoJobStorage($connectionFactory);
```

The storage will automatically:
1. Detect stale connections using a health check (`SELECT 1`)
2. Call the factory to create a fresh connection when needed
3. Continue processing without crashing

You can also force a reconnection manually:

```php
$storage->reconnect(); // Next operation will use a fresh connection
```

### Custom Job Storage

Implement `JobStorageInterface` for custom storage:

```php
use Oeltima\SimpleQueue\Contract\JobStorageInterface;
use Oeltima\SimpleQueue\Contract\JobData;

class MongoJobStorage implements JobStorageInterface
{
    public function createJob(string $type, array $payload, ...): int { ... }
    public function find(int $id): ?JobData { ... }
    // Implement all interface methods
}
```

### Custom Queue Driver

Implement `QueueDriverInterface` for custom drivers:

```php
use Oeltima\SimpleQueue\Contract\QueueDriverInterface;

class RabbitMQDriver implements QueueDriverInterface
{
    public function isAvailable(): bool { ... }
    public function enqueue(string $queue, int $jobId): void { ... }
    public function dequeue(string $queue, int $timeoutSeconds): ?int { ... }
    public function ack(string $queue, int $jobId): void { ... }
    public function nack(string $queue, int $jobId): void { ... }
}
```

### Handling Job Failures

Jobs automatically retry with exponential backoff:
- Attempt 1 fails → retry after 2 seconds
- Attempt 2 fails → retry after 4 seconds
- Attempt 3 fails → marked as failed

Access error information:
```php
$job = $dispatcher->getStatus($jobId);
if ($job->status === 'failed') {
    echo $job->errorMessage;
    echo $job->errorTrace;
}
```

### Testing

Use in-memory implementations for testing:

```php
use Oeltima\SimpleQueue\Driver\InMemoryQueueDriver;
use Oeltima\SimpleQueue\Storage\InMemoryJobStorage;

$storage = new InMemoryJobStorage();
$driver = new InMemoryQueueDriver();
$queueManager = new QueueManager($driver);

// Dispatch and process synchronously
$dispatcher = new JobDispatcher($storage, $queueManager);
$jobId = $dispatcher->dispatch('test.job', ['data' => 'value']);

$worker = new Worker($storage, $queueManager, $registry);
$worker->processOne(); // Process single job

$job = $storage->find($jobId);
$this->assertEquals('completed', $job->status);
```

## API Reference

### JobDispatcher

| Method | Description |
|--------|-------------|
| `dispatch(string $type, array $payload, ...)` | Queue a single job |
| `dispatchBatch(string $type, array $payloads, ...)` | Queue multiple jobs |
| `getStatus(int $jobId)` | Get job details |

### Worker

| Method | Description |
|--------|-------------|
| `run()` | Start the worker loop |
| `processOne()` | Process a single job |
| `stop()` | Signal the worker to stop |
| `getWorkerId()` | Get the worker identifier |

### JobData

| Property | Type | Description |
|----------|------|-------------|
| `id` | int | Job ID |
| `type` | string | Job type identifier |
| `status` | string | pending, running, completed, failed, cancelled |
| `payload` | array | Job data |
| `progress` | ?int | Progress percentage (0-100) |
| `progressMessage` | ?string | Progress status message |
| `result` | mixed | Job result (when completed) |
| `errorMessage` | ?string | Error message (when failed) |
| `attempts` | int | Number of attempts made |

## Contributing

Contributions are welcome! Please see [CONTRIBUTING.md](CONTRIBUTING.md) for details.

## Security

If you discover a security vulnerability, please send an email to gema@oeltimacreation.com instead of using the issue tracker.

## License

The MIT License (MIT). Please see [LICENSE](LICENSE) for more information.
