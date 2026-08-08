# OeltimaCreation PHP SimpleQueue

A small, lightweight, framework-agnostic PHP library for durable background job processing.

SimpleQueue is designed to be physically compact and easy to hold in one head while delivering enterprise-grade job guarantees. Storage is authoritative for job persistence, state transitions, and leases, while Redis or database polling acts as the delivery notification layer.

## Key Features

| Feature | Description |
|---|---|
| **Zero Heavy Dependencies** | Requires only `psr/container` and `psr/log`. Redis and PDO remain optional. |
| **Two-Layer Architecture** | Authoritative storage (PDO / In-Memory) decoupled from notification drivers (Redis / DB / In-Memory). |
| **Scheduled Dispatch** | Delay initial job availability using `dispatchAfter()`, `dispatchAt()`, or `$availableAt`. |
| **At-Least-Once Delivery & Fencing** | Worker claims use worker IDs and lease tokens to fence completion, retry, and progress updates. |
| **Idempotency & Deduplication** | `dispatchIdempotent()` prevents duplicate active jobs for a given request ID. |
| **Bounded Queue Repair** | Built-in `QueueReconciler` detects lost notifications and repairs stale leases safely. |
| **PHP 8.2+ Modernization** | Strict types everywhere (`declare(strict_types=1)`), `readonly` value objects, and PHPStan Level 9 strict compliance. |

## Requirements

- **PHP**: 8.2 or later
- **Database (Optional)**: PDO (MySQL, PostgreSQL, SQLite) for durable persistence
- **Redis / Valkey (Optional)**: Redis 7+ or Valkey 8+ with `predis/predis:^3` for Redis delivery

## Installation

```bash
composer require oeltimacreation/php-simplequeue

# Optional: install Predis only if using the Redis queue driver
composer require predis/predis
```

Create the `background_jobs` table using the schema for your database in [the database guide](docs/database.md).

## Quick Start

The fastest way to see the complete dispatch → process → inspect lifecycle is using the in-memory driver and storage:

```php
<?php

declare(strict_types=1);

use Oeltima\SimpleQueue\Contract\JobHandlerInterface;
use Oeltima\SimpleQueue\Driver\InMemoryQueueDriver;
use Oeltima\SimpleQueue\JobDispatcher;
use Oeltima\SimpleQueue\JobRegistry;
use Oeltima\SimpleQueue\QueueManager;
use Oeltima\SimpleQueue\Storage\InMemoryJobStorage;
use Oeltima\SimpleQueue\Worker;
use Oeltima\SimpleQueue\WorkerOptions;

final class WelcomeEmailHandler implements JobHandlerInterface
{
    public function handle(int $jobId, array $payload, ?callable $progress = null): mixed
    {
        if ($progress !== null) {
            $progress(percent: 100, message: 'Email sent');
        }

        return ['recipient' => $payload['email']];
    }
}

// 1. Initialize components
$storage = new InMemoryJobStorage();
$queues = new QueueManager(driver: new InMemoryQueueDriver());
$registry = new JobRegistry();
$registry->register(type: 'email.welcome', handler: WelcomeEmailHandler::class);

// 2. Dispatch a job
$dispatcher = new JobDispatcher(storage: $storage, queueManager: $queues);
$jobId = $dispatcher->dispatch(type: 'email.welcome', payload: ['email' => 'ada@example.test']);

// 3. Process the job with a worker
$workerOptions = WorkerOptions::fromArray(['lock_file' => null]);
$worker = new Worker(
    storage: $storage,
    queueDriver: $queues,
    registry: $registry,
    queue: 'default',
    options: $workerOptions,
);
$worker->processOne();

// 4. Inspect job status
echo $dispatcher->getStatus(jobId: $jobId)?->status->value; // 'completed'
```

For runnable examples with durable databases and Redis, see [examples/](examples/README.md).

## Scheduled Dispatch

Delay a job's first availability using `dispatchAfter()`, `dispatchAt()`, or the `$availableAt` parameter:

```php
// Dispatch 5 minutes into the future
$jobId = $dispatcher->dispatchAfter(
    delaySeconds: 300,
    type: 'email.welcome',
    payload: ['email' => 'ada@example.test'],
);

// Dispatch at a specific timestamp
$jobId = $dispatcher->dispatchAt(
    timestamp: strtotime('tomorrow 09:00'),
    type: 'email.welcome',
    payload: ['email' => 'ada@example.test'],
);

// Dispatch via optional availableAt parameter
$jobId = $dispatcher->dispatch(
    type: 'email.welcome',
    payload: ['email' => 'ada@example.test'],
    availableAt: new DateTimeImmutable('+1 hour'),
);
```

## Quick API Reference

| Class | Key Methods | Description |
|---|---|---|
| `JobDispatcher` | `dispatch()`, `dispatchAfter()`, `dispatchAt()`, `dispatchBatch()`, `dispatchIdempotent()`, `getStatus()` | Main entry point for enqueueing jobs and querying status. |
| `Worker` | `run()`, `processOne()`, `withOptions()` | Worker loop executing jobs with signal handling and lease heartbeat. |
| `QueueManager` | `create()`, `redis()`, `database()`, `inMemory()` | Driver factory supporting auto-selection and driver resolution. |
| `JobRegistry` | `register()`, `get()`, `has()` | Handler registry mapping job type strings to handler classes or callables. |
| `PdoJobStorage` | `createJobs()`, `claimNextAvailable()`, `complete()`, `fail()`, `retry()` | Durable database persistence implementing `JobStorageInterface`. |

## Documentation Index

- **[Getting Started](docs/getting-started.md)** — Durable database setup and worker setup
- **[Database Guide](docs/database.md)** — Schemas, indexes, transactions, and idempotency
- **[Configuration](docs/configuration.md)** — Driver auto-selection, polling, and worker options
- **[Operations](docs/operations.md)** — Deployment, supervisor configuration, monitoring, and repair
- **[Architecture](docs/architecture.md)** — Two-layer model, lifecycle state machine, and lease fencing
- **[Extending](docs/extending.md)** — Custom handlers, storage implementations, and drivers
- **[Upgrading](docs/upgrading.md)** — Version upgrade instructions and migration guides
- **[Runnable Examples](examples/README.md)** — Sample catalogue and benchmark scripts

## Delivery Guarantees

SimpleQueue provides **at-least-once delivery**. Handlers must be written to be idempotent (e.g. using database transaction unique constraints or payment reference checks).

For cross-process request deduplication, use `dispatchIdempotent()` along with the unique request ID index described in the [database guide](docs/database.md).

## Development & Quality Gates

```bash
composer check        # Full quality check (tests, PHPStan Level 9, PHPCS, quality ratchet)
composer test         # Run PHPUnit test suite
composer phpstan      # Run static analysis
composer cs-check     # Run code style check
composer cs-fix       # Auto-fix code style issues
```

See [CONTRIBUTING.md](CONTRIBUTING.md) for contribution guidelines and [LICENSE](LICENSE) for license details.


