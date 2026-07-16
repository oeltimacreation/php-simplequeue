# OeltimaCreation PHP SimpleQueue

A small, framework-agnostic PHP queue for durable background jobs. Job data is
stored in a database; Redis or database polling delivers work to workers.

It supports retries with backoff, delayed retries, progress reporting,
lease-based job ownership, graceful shutdown, and bounded queue repair.

## Requirements

- PHP 8.2 or later
- PDO and a supported database for durable jobs
- Redis 7+ or Valkey 8+ with `predis/predis:^3` for Redis delivery (optional)

## Install

```bash
composer require oeltimacreation/php-simplequeue
composer require predis/predis # only when using the Redis driver
```

Create the `background_jobs` table using the schema for your database in
[the database guide](docs/database.md).

## Quick start

The in-memory sample has no services to configure and is the fastest way to
see the complete dispatch → process → inspect flow:

```bash
php examples/basic/in-memory.php
```

For a durable Redis setup, configure the environment variables shown in
[the Redis example](examples/redis/README.md), then run the worker and
dispatcher in separate terminals.

```php
use Oeltima\SimpleQueue\Contract\JobHandlerInterface;
use Oeltima\SimpleQueue\Driver\InMemoryQueueDriver;
use Oeltima\SimpleQueue\JobDispatcher;
use Oeltima\SimpleQueue\JobRegistry;
use Oeltima\SimpleQueue\QueueManager;
use Oeltima\SimpleQueue\Storage\InMemoryJobStorage;
use Oeltima\SimpleQueue\Worker;

final class WelcomeEmail implements JobHandlerInterface
{
    public function handle(int $jobId, array $payload, ?callable $progress = null): mixed
    {
        if ($progress !== null) {
            $progress(100, 'Email sent');
        }

        return ['recipient' => $payload['email']];
    }
}

$storage = new InMemoryJobStorage();
$queues = new QueueManager(new InMemoryQueueDriver());
$registry = new JobRegistry();
$registry->register('email.welcome', WelcomeEmail::class);
$dispatcher = new JobDispatcher($storage, $queues);

$jobId = $dispatcher->dispatch('email.welcome', ['email' => 'ada@example.test']);
(new Worker($storage, $queues, $registry, queue: 'default', options: ['lock_file' => null]))->processOne();

echo $dispatcher->getStatus($jobId)?->status->value; // completed
```

## Documentation

- [Getting started](docs/getting-started.md) — durable setup and first worker
- [Configuration](docs/configuration.md) — drivers and worker options
- [Database guide](docs/database.md) — schemas, indexes, and idempotency
- [Operations](docs/operations.md) — deployment, repair, retention, monitoring
- [Architecture](docs/architecture.md) — delivery and ownership model
- [Extending](docs/extending.md) — custom handlers, storage, and drivers
- [Upgrading](docs/upgrading.md) — supported upgrade paths
- [Examples](examples/README.md) — runnable sample catalogue

## Important delivery rule

SimpleQueue provides **at-least-once delivery**. A job can run more than once
if a worker completes a side effect and stops before its acknowledgement is
stored. Make every handler idempotent: use transaction IDs, unique database
constraints, or provider idempotency keys for external side effects.

`dispatchIdempotent()` prevents duplicate *active* jobs for one request ID.
For cross-process safety with `PdoJobStorage`, keep the conditional/generated
active-request-ID unique index from the database guide.

## Development

```bash
composer check        # tests, PHPStan, and coding style
composer test
composer phpstan
composer cs-check
composer test-coverage
```

See [CONTRIBUTING.md](CONTRIBUTING.md) for contribution details,
[SECURITY.md](SECURITY.md) for vulnerability reporting, and [LICENSE](LICENSE)
for license terms.
