<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Contract;

use Oeltima\SimpleQueue\Contract\QueueDriverInterface;
use Oeltima\SimpleQueue\Contract\QueueStatsInterface;
use Oeltima\SimpleQueue\Contract\SupportsBatchEnqueue;
use Oeltima\SimpleQueue\Contract\SupportsDelayedJobs;
use Oeltima\SimpleQueue\Driver\InMemoryQueueDriver;
use Oeltima\SimpleQueue\Driver\RedisQueueDriver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Predis\Client;

final class QueueDriverContractTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function backends(): iterable
    {
        yield 'in-memory' => ['memory'];
        yield 'Redis' => ['redis'];
    }

    #[DataProvider('backends')]
    public function testLifecyclePreservesQueueIsolationAndAcknowledgement(string $backend): void
    {
        $driver = $this->driver($backend);
        try {
            $driver->enqueue('alpha', 11);
            $driver->enqueue('beta', 22);
            self::assertSame(11, $driver->dequeue('alpha', 0));
            $driver->ack('alpha', 11);
            self::assertNull($driver->dequeue('alpha', 0));
            self::assertSame(22, $driver->dequeue('beta', 0));
            $driver->ack('beta', 22);
        } finally {
            $this->clear($driver, 'alpha');
            $this->clear($driver, 'beta');
        }
    }

    #[DataProvider('backends')]
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

    #[DataProvider('backends')]
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

    #[DataProvider('backends')]
    public function testInvalidJobIdMessageMatches(string $backend): void
    {
        $driver = $this->driver($backend);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Job ID must be a positive integer');
        $driver->enqueue('default', 0);
    }

    #[DataProvider('backends')]
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
