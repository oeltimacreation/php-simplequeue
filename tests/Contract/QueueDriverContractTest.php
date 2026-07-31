<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Contract;

use Oeltima\SimpleQueue\Contract\QueueDriverInterface;
use Oeltima\SimpleQueue\Contract\QueueStatsInterface;
use Oeltima\SimpleQueue\Contract\SupportsBatchEnqueue;
use Oeltima\SimpleQueue\Contract\SupportsDelayedJobs;
use Oeltima\SimpleQueue\Driver\DatabaseQueueDriver;
use Oeltima\SimpleQueue\Driver\InMemoryQueueDriver;
use Oeltima\SimpleQueue\Driver\RedisQueueDriver;
use Oeltima\SimpleQueue\Storage\InMemoryJobStorage;
use Oeltima\SimpleQueue\Tests\Support\FrozenClock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Predis\Client;

enum QueueBackend
{
    case Memory;
    case Database;
    case Redis;

    public function invalidJobIdMessage(): string
    {
        return $this === self::Database
            ? 'jobId must be a positive integer'
            : 'Job ID must be a positive integer';
    }
}

final class QueueDriverContractTest extends TestCase
{
    private ?InMemoryJobStorage $databaseStorage = null;

    /** @return iterable<string, array{QueueBackend}> */
    public static function queueBackends(): iterable
    {
        yield 'in-memory' => [QueueBackend::Memory];
        yield 'database' => [QueueBackend::Database];
        yield 'Redis' => [QueueBackend::Redis];
    }

    /** @return iterable<string, array{QueueBackend}> */
    public static function notificationBackends(): iterable
    {
        yield 'in-memory' => [QueueBackend::Memory];
        yield 'Redis' => [QueueBackend::Redis];
    }

    /** @return iterable<string, array{QueueBackend}> */
    public static function jobIdBackends(): iterable
    {
        yield from self::queueBackends();
    }

    #[DataProvider('queueBackends')]
    public function testLifecyclePreservesQueueIsolationAndAcknowledgement(QueueBackend $backend): void
    {
        $driver = $this->driver($backend);
        try {
            $alphaId = $this->enqueueJob($backend, $driver, 'alpha', 11);
            $betaId = $this->enqueueJob($backend, $driver, 'beta', 22);
            self::assertSame($alphaId, $driver->dequeue('alpha', 0));
            $driver->ack('alpha', $alphaId);
            self::assertNull($driver->dequeue('alpha', 0));
            self::assertSame($betaId, $driver->dequeue('beta', 0));
            $driver->ack('beta', $betaId);
        } finally {
            $this->clear($driver, 'alpha');
            $this->clear($driver, 'beta');
        }
    }

    #[DataProvider('notificationBackends')]
    public function testBatchOrderingAndCountsMatch(QueueBackend $backend): void
    {
        $driver = $this->driver($backend);
        self::assertInstanceOf(SupportsBatchEnqueue::class, $driver);
        self::assertInstanceOf(QueueStatsInterface::class, $driver);
        try {
            $driver->enqueueBatch('batch', [1, 2, 3]);
            self::assertSame(3, $driver->getPendingCount('batch'));
            self::assertSame([1, 2, 3], [
                $driver->dequeue('batch', 0),
                $driver->dequeue('batch', 0),
                $driver->dequeue('batch', 0),
            ]);
            self::assertSame(3, $driver->getProcessingCount('batch'));
        } finally {
            $this->clear($driver, 'batch');
        }
    }

    #[DataProvider('notificationBackends')]
    public function testImmediateAndDelayedRetriesMatch(QueueBackend $backend): void
    {
        $driver = $this->driver($backend);
        self::assertInstanceOf(SupportsDelayedJobs::class, $driver);
        self::assertInstanceOf(QueueStatsInterface::class, $driver);
        try {
            $driver->enqueue('retry', 31);
            self::assertSame(31, $driver->dequeue('retry', 0));
            $driver->nack('retry', 31);
            self::assertSame(31, $driver->dequeue('retry', 0));
            $driver->nack('retry', 31, 60);
            self::assertNull($driver->dequeue('retry', 0));
            self::assertSame(1, $driver->getDelayedCount('retry'));
            self::assertSame(0, $driver->promoteDelayedJobs('retry'));
        } finally {
            $this->clear($driver, 'retry');
        }
    }

    #[DataProvider('delayedNotificationScenarios')]
    public function testDelayedNotificationsBecomeClaimableWhenDue(QueueBackend $backend, array $scenario): void
    {
        $driver = $this->driver($backend);
        self::assertInstanceOf(SupportsDelayedJobs::class, $driver);
        self::assertInstanceOf(QueueStatsInterface::class, $driver);
        try {
            $scenario['enqueue_future']($driver);
            self::assertSame($scenario['future_count'], $driver->getDelayedCount('sched'));
            self::assertNull($driver->dequeue('sched', 0));
            self::assertSame(0, $driver->promoteDelayedJobs('sched'));

            $scenario['enqueue_due']($driver);
            self::assertSame(1, $driver->promoteDelayedJobs('sched'));
            self::assertSame($scenario['due_job_id'], $driver->dequeue('sched', 0));
        } finally {
            $this->clear($driver, 'sched');
        }
    }

    /**
     * @return iterable<string, array{QueueBackend, array{
     *     enqueue_future: callable(SupportsDelayedJobs): void,
     *     future_count: int,
     *     enqueue_due: callable(SupportsDelayedJobs): void,
     *     due_job_id: int
     * }}>
     */
    public static function delayedNotificationScenarios(): iterable
    {
        foreach (self::notificationBackends() as $label => $arguments) {
            $backend = $arguments[0];
            yield 'single ' . $label => [$backend, [
                'enqueue_future' => static fn (SupportsDelayedJobs $driver) => $driver->enqueueDelayed('sched', 61, time() + 60),
                'future_count' => 1,
                'enqueue_due' => static fn (SupportsDelayedJobs $driver) => $driver->enqueueDelayed('sched', 62, time() - 10),
                'due_job_id' => 62,
            ]];
            yield 'batch ' . $label => [$backend, [
                'enqueue_future' => static fn (SupportsDelayedJobs $driver) => $driver->enqueueDelayedBatch('sched', [71, 72, 73], time() + 60),
                'future_count' => 3,
                'enqueue_due' => static fn (SupportsDelayedJobs $driver) => $driver->enqueueDelayedBatch('sched', [74], time() - 10),
                'due_job_id' => 74,
            ]];
        }
    }

    #[DataProvider('jobIdBackends')]
    public function testInvalidJobIdMessageIsPreserved(QueueBackend $backend): void
    {
        $driver = $this->driver($backend);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($backend->invalidJobIdMessage());
        $driver->enqueue('default', 0);
    }

    #[DataProvider('notificationBackends')]
    public function testInvalidRetryDelayMessageMatches(QueueBackend $backend): void
    {
        $driver = $this->driver($backend);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Retry delay must not be negative');
        $driver->nack('default', 1, -1);
    }

    private function driver(QueueBackend $backend): QueueDriverInterface
    {
        if ($backend === QueueBackend::Memory) {
            return new InMemoryQueueDriver();
        }
        if ($backend === QueueBackend::Database) {
            $clock = new FrozenClock();
            $this->databaseStorage = new InMemoryJobStorage($clock);
            return new DatabaseQueueDriver($this->databaseStorage, 50, $clock);
        }

        $host = getenv('REDIS_HOST');
        if (!is_string($host) || $host === '') {
            self::markTestSkipped('REDIS_HOST is not set. Skipping Redis queue contract.');
        }
        $port = getenv('REDIS_PORT') ?: '6379';
        $client = new Client(['scheme' => 'tcp', 'host' => $host, 'port' => (int) $port]);
        try {
            $client->connect();
        } catch (\Throwable $exception) {
            self::markTestSkipped('Could not connect to Redis: ' . $exception->getMessage());
        }
        return new RedisQueueDriver($client, 'contract-' . bin2hex(random_bytes(8)));
    }

    private function enqueueJob(
        QueueBackend $backend,
        QueueDriverInterface $driver,
        string $queue,
        int $notificationId
    ): int {
        if ($backend === QueueBackend::Database) {
            if ($this->databaseStorage === null) {
                throw new \LogicException('Database storage was not initialized');
            }
            $jobId = $this->databaseStorage->createJob('contract.job', [], $queue);
            $driver->enqueue($queue, $jobId);
            return $jobId;
        }
        $driver->enqueue($queue, $notificationId);
        return $notificationId;
    }

    private function clear(QueueDriverInterface $driver, string $queue): void
    {
        if ($driver instanceof RedisQueueDriver) {
            $driver->clear($queue);
            return;
        }
        if ($driver instanceof InMemoryQueueDriver) {
            $driver->clear();
        }
    }
}
