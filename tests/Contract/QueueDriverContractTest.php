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

final class QueueDriverContractTest extends TestCase
{
    private ?InMemoryJobStorage $databaseStorage = null;

    /** @return iterable<string, array{string}> */
    public static function queueBackends(): iterable
    {
        yield 'in-memory' => ['memory'];
        yield 'database' => ['database'];
        yield 'Redis' => ['redis'];
    }

    /** @return iterable<string, array{string}> */
    public static function notificationBackends(): iterable
    {
        yield 'in-memory' => ['memory'];
        yield 'Redis' => ['redis'];
    }

    /** @return iterable<string, array{string, string}> */
    public static function jobIdBackends(): iterable
    {
        yield 'in-memory' => ['memory', 'Job ID must be a positive integer'];
        yield 'database' => ['database', 'jobId must be a positive integer'];
        yield 'Redis' => ['redis', 'Job ID must be a positive integer'];
    }

    #[DataProvider('queueBackends')]
    public function testLifecyclePreservesQueueIsolationAndAcknowledgement(string $backend): void
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
    public function testBatchOrderingAndCountsMatch(string $backend): void
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
    public function testImmediateAndDelayedRetriesMatch(string $backend): void
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

    #[DataProvider('jobIdBackends')]
    public function testInvalidJobIdMessageIsPreserved(string $backend, string $message): void
    {
        $driver = $this->driver($backend);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        $driver->enqueue('default', 0);
    }

    #[DataProvider('notificationBackends')]
    public function testInvalidRetryDelayMessageMatches(string $backend): void
    {
        $driver = $this->driver($backend);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Retry delay must not be negative');
        $driver->nack('default', 1, -1);
    }

    private function driver(string $backend): QueueDriverInterface
    {
        if ($backend === 'memory') {
            return new InMemoryQueueDriver();
        }
        if ($backend === 'database') {
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
        string $backend,
        QueueDriverInterface $driver,
        string $queue,
        int $notificationId
    ): int {
        if ($backend === 'database') {
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
