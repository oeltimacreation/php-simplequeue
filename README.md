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

- PHP 8.2 or higher (fully tested on PHP 8.2 through 8.5)
- Redis >= 7.0 or Valkey >= 8.0 (optional, for Redis/Valkey driver)
- PDO (optional, for database driver)

### Platform Compatibility Matrix

| Library Version | PHP Version | Redis Version | Valkey Version | Primary QA Gates |
| :--- | :--- | :--- | :--- | :--- |
| **1.4.x** (Current) | PHP 8.2 – 8.5 | Redis >= 7.0 | Valkey >= 8.0 | PHPStan L9 + Strict, PHPCS 4.0 + Slevomat |
| **1.3.x** | PHP 8.1 – 8.4 | Redis >= 6.2 | N/A | PHPStan L8, PSR-12 |

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

Create the jobs table. For details on MySQL, PostgreSQL, and SQLite, see [database-schema.sql](file:///home/nerdv2/work/Oeltimacreation/php-simplequeue/examples/database-schema.sql).

### MySQL / MariaDB Schema

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
    available_at DATETIME NOT NULL,
    started_at DATETIME DEFAULT NULL,
    completed_at DATETIME DEFAULT NULL,
    locked_by VARCHAR(255) DEFAULT NULL,
    locked_at DATETIME DEFAULT NULL,
    lease_token VARCHAR(64) DEFAULT NULL,
    error_message TEXT DEFAULT NULL,
    error_trace TEXT DEFAULT NULL,
    request_id VARCHAR(255) DEFAULT NULL,
    -- Generated virtual column to enforce unique active request_id (idempotency)
    active_request_id VARCHAR(255) GENERATED ALWAYS AS (
        CASE WHEN status IN ('pending', 'running') THEN request_id ELSE NULL END
    ) VIRTUAL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    
    INDEX idx_queue_status (queue, status),
    INDEX idx_claim_ready (queue, status, available_at, id),
    INDEX idx_status_available (status, available_at),
    INDEX idx_locked_at (locked_at),
    INDEX idx_lease_token (lease_token),
    INDEX idx_type (type),
    INDEX idx_request_id (request_id),
    UNIQUE KEY uq_active_request_id (active_request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Idempotent Dispatch & Unique Constraints

To prevent race conditions where concurrent calls to `JobDispatcher::dispatchIdempotent()` create duplicate jobs, you should add a conditional unique constraint on the `request_id` column:

- **MySQL 5.7+ / MariaDB**: Add a virtual generated column mapping active jobs (pending/running) to the `request_id` and define a unique key on it (included in the schema above).
- **PostgreSQL**: Define a partial unique index:
  ```sql
  CREATE UNIQUE INDEX uq_active_request_id ON background_jobs (request_id) WHERE status IN ('pending', 'running');
  ```
- **SQLite**: Define a partial unique index:
  ```sql
  CREATE UNIQUE INDEX uq_active_request_id ON background_jobs (request_id) WHERE status IN ('pending', 'running');
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
1. Optimistically execute queries on the database.
2. If a connection loss exception is caught (e.g., MySQL server has gone away), it will immediately clear the stale connection, invoke the factory to establish a fresh connection, and retry the operation once.
3. Continue processing without crashing, eliminating the overhead of running `SELECT 1` before every query.

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

### Idempotency & At-Least-Once Caveats

OeltimaCreation SimpleQueue guarantees **at-least-once delivery**. In rare scenarios (e.g. worker crashing after finishing a task but before acknowledging it, network partition, or lease expiration), a job may be executed more than once. 

**Therefore, your job handlers MUST be idempotent.**

#### Idempotent Dispatching

The library provides `dispatchIdempotent()` to prevent duplicate active jobs for the same unique transaction or request. For this to be safe under concurrent calls, you **must** configure database unique constraints:

```php
// Dispatch a job with a unique request ID
$result = $dispatcher->dispatchIdempotent(
    type: 'order.process',
    payload: ['order_id' => 12345],
    requestId: 'req_order_12345'
);

if ($result['created']) {
    echo "Dispatched new job: " . $result['job_id'];
} else {
    echo "Using existing active job: " . $result['job_id'];
}
```

Ensure your database enforces this uniqueness. See the **Database Schema** section for MySQL, PostgreSQL, and SQLite configurations.

---

### Safe Predis Timeout Configuration

If you use the Redis queue driver with a blocking dequeue call (when `poll_timeout` is positive), you must configure your Predis connection timeout carefully. If Predis's `read_write_timeout` is less than or equal to the worker's `poll_timeout`, Predis will close the connection while waiting, causing connection errors in the worker.

When starting up, the worker automatically validates this configuration. Ensure your Predis client is configured as follows:

```php
use Predis\Client;

$redis = new Client([
    'host' => '127.0.0.1',
    'port' => 6379,
    // Set read_write_timeout to -1 (disable) or a value higher than your poll_timeout (e.g. 60)
    'read_write_timeout' => -1,
]);
```

---

### Queue Statistics & Monitoring

If your queue driver supports statistics (implements `QueueStatsInterface` like `RedisQueueDriver`), you can query queue sizes and processing status:

```php
use Oeltima\SimpleQueue\Contract\QueueStatsInterface;

if ($queueManager->getDriver() instanceof QueueStatsInterface) {
    /** @var QueueStatsInterface $driver */
    $driver = $queueManager->getDriver();
    
    $pending = $driver->getPendingCount('default');
    $processing = $driver->getProcessingCount('default');
    $delayed = $driver->getDelayedCount('default');
    
    echo "Pending: $pending | Processing: $processing | Delayed: $delayed\n";
}
```

---

## Migration from v1.2.x to v1.3.0

v1.3.0 introduces breaking changes to resolve concurrency races and improve performance:

### 1. Database Schema Update
You must add a `lease_token` column and alter the `available_at` column. See the [1.3.0 migration script](file:///home/nerdv2/work/Oeltimacreation/php-simplequeue/examples/migrations/1.3.0-lease-based-claims.sql) for details.

### 2. JobStorageInterface Changes
If you have written custom job storage backends, you must implement the new lease-based claim flow:
- Implement `claimNextAvailable(string $queue, string $workerId): ?ClaimedJob`.
- Implement `claimById(int $id, string $workerId): ?ClaimedJob`.
- Update `markCompleted`, `markFailed`, `updateProgress`, `scheduleRetry`, and `heartbeat` to accept a `ClaimedJob` instead of `$jobId`.
- Remove the deprecated `getNextPendingJobId()` and `claimJob()`.

---

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
