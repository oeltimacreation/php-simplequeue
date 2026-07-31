# Getting started

This guide creates a durable queue using MySQL/MariaDB and Redis. For a
service-free walkthrough, run `php examples/basic/in-memory.php` instead.

## 1. Install and create the table

```bash
composer require oeltimacreation/php-simplequeue predis/predis
```

Apply the MySQL/MariaDB schema in [database.md](database.md). PostgreSQL and
SQLite variants are provided there too.

## 2. Register a handler

```php
use Oeltima\SimpleQueue\Contract\JobHandlerInterface;

final class SendReceipt implements JobHandlerInterface
{
    public function handle(int $jobId, array $payload, ?callable $progress = null): mixed
    {
        if ($progress !== null) {
            $progress(50, 'Sending receipt');
        }

        // Send the receipt for $payload['order_id'] here.

        if ($progress !== null) {
            $progress(100, 'Receipt sent');
        }

        return ['order_id' => $payload['order_id']];
    }
}
```

## 3. Build the queue services

Use a connection factory for a long-running worker so the storage can replace a
stale database connection.

```php
use Oeltima\SimpleQueue\JobDispatcher;
use Oeltima\SimpleQueue\JobRegistry;
use Oeltima\SimpleQueue\QueueManager;
use Oeltima\SimpleQueue\Storage\PdoJobStorage;
use Predis\Client;

$storage = new PdoJobStorage(static fn (): PDO => new PDO(
    'mysql:host=127.0.0.1;dbname=app;charset=utf8mb4',
    'app',
    'secret',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
));
$redis = new Client(['host' => '127.0.0.1', 'read_write_timeout' => -1]);
$queues = QueueManager::redis($redis, 'app');

$registry = new JobRegistry();
$registry->register('receipt.send', SendReceipt::class);
$dispatcher = new JobDispatcher($storage, $queues);
```

Database polling is available when Redis is not desired:

```php
$queues = QueueManager::database($storage);
```

## 4. Dispatch and process

```php
$jobId = $dispatcher->dispatch('receipt.send', ['order_id' => 42]);

$worker = new Oeltima\SimpleQueue\Worker(
    storage: $storage,
    queueManager: $queues,
    registry: $registry,
    options: ['lock_file' => '/var/run/app-receipts.lock'],
);
$worker->run();
```

Run the worker under a process supervisor in production. See
[operations.md](operations.md) for worker settings, exit codes, repair, and
retention.

## 5. Schedule a job's first run

Pass an optional availability time to `dispatch()`, `dispatchBatch()`, or use
the `dispatchAt()` / `dispatchAfter()` conveniences. A scheduled job is stored
with a future `available_at`, receives a delayed notification, and is claimed
only once that timestamp arrives:

```php
// Run five minutes from now.
$jobId = $dispatcher->dispatchAfter(300, 'receipt.send', ['order_id' => 42]);

// Same result with an absolute timestamp or a DateTimeInterface.
$jobId = $dispatcher->dispatchAt(strtotime('tomorrow 09:00'), 'receipt.send', ['order_id' => 42]);
$jobId = $dispatcher->dispatchAt(
    new DateTimeImmutable('2026-08-01 09:00:00', new DateTimeZone('UTC')),
    'receipt.send',
    ['order_id' => 42],
);

// dispatch() and dispatchBatch() accept the same optional parameter.
$jobId = $dispatcher->dispatch('receipt.send', ['order_id' => 42], availableAt: $timestamp);
$ids = $dispatcher->dispatchBatch(
    'receipt.send',
    [['order_id' => 1], ['order_id' => 2]],
    availableAt: $timestamp,
);
```

Timestamps in the past or equal to now use the immediate dispatch path, so a
scheduled dispatch never delays a job that is already due. `dispatchAfter(0)`
is immediate. Negative delays and non-positive timestamps are rejected with an
`InvalidArgumentException`.

With Redis, the scheduled notification lives in the queue's delayed ZSET and
the worker promotes it when due. With database polling, the claim query itself
gates on `available_at`, so no notification change is needed.
