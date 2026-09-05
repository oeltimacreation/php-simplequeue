<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Unit;

use Oeltima\SimpleQueue\Driver\DatabaseQueueDriver;
use Oeltima\SimpleQueue\Storage\InMemoryJobStorage;
use Oeltima\SimpleQueue\Contract\JobStatus;
use PHPUnit\Framework\TestCase;

final class DatabaseQueueDriverTest extends TestCase
{
    private InMemoryJobStorage $storage;
    private DatabaseQueueDriver $driver;

    protected function setUp(): void
    {
        $this->storage = new InMemoryJobStorage();
        $this->driver = new DatabaseQueueDriver($this->storage, 50); // Set poll interval to 50ms for tests
    }

    public function testEnqueueThrowsExceptionForInvalidJobId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('jobId must be a positive integer');
        $this->driver->enqueue('default', 0);
    }

    public function testEnqueueWithPositiveJobIdDoesNothing(): void
    {
        $this->expectNotToPerformAssertions();
        $this->driver->enqueue('default', 42);
    }

    public function testAckThrowsExceptionForInvalidJobId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('jobId must be a positive integer');
        $this->driver->ack('default', -1);
    }

    public function testAckWithPositiveJobIdDoesNothing(): void
    {
        $this->expectNotToPerformAssertions();
        $this->driver->ack('default', 42);
    }

    public function testNackThrowsExceptionForInvalidJobId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('jobId must be a positive integer');
        $this->driver->nack('default', 0, 10);
    }

    public function testNackWithPositiveJobIdDoesNothing(): void
    {
        $this->expectNotToPerformAssertions();
        $this->driver->nack('default', 42, 10);
    }

    public function testDequeueReturnsNullWhenNoJobAvailableAndTimeoutExpires(): void
    {
        $start = microtime(true);
        $result = $this->driver->dequeue('default', 2);
        $duration = microtime(true) - $start;

        self::assertNull($result);
        self::assertGreaterThanOrEqual(1.0, $duration);
    }

    public function testDequeueReturnsJobIdImmediatelyWhenAvailable(): void
    {
        $jobId = $this->storage->createJob('test', ['foo' => 'bar'], 'default');

        $result = $this->driver->dequeue('default', 5);

        self::assertEquals($jobId, $result);
    }

    public function testDequeueClaimedReturnsTheOriginalStorageClaim(): void
    {
        $jobId = $this->storage->createJob('test', [], 'default');

        $claim = $this->driver->dequeueClaimed('default', 0);

        self::assertNotNull($claim);
        self::assertSame($jobId, $claim->job->id);
        self::assertSame($claim->leaseToken, $claim->job->leaseToken);
    }

    public function testDequeueFiltersByQueue(): void
    {
        $this->storage->createJob('test', ['foo' => 'bar'], 'other-queue');

        $result = $this->driver->dequeue('default', 0);
        self::assertNull($result);

        $result2 = $this->driver->dequeue('other-queue', 0);
        self::assertNotNull($result2);
    }

    public function testDequeuePollsUntilJobAvailable(): void
    {
        // Enqueue a job that will be created shortly after we start dequeueing.
        // We simulate this by having usleep/polling.
        // Since we poll every 50ms, we can spawn a job or set it up in the database.
        // Actually, we can run a test where the job is delayed and then becomes available,
        // or just verify that the driver loops and uses claimNextAvailable multiple times.

        // Let's mock the storage to verify it gets called multiple times.
        $mockStorage = $this->createMock(\Oeltima\SimpleQueue\Contract\JobStorageInterface::class);
        $mockStorage->expects($this->exactly(3))
            ->method('claimNextAvailable')
            ->willReturnOnConsecutiveCalls(null, null, new \Oeltima\SimpleQueue\Contract\ClaimedJob(
                new \Oeltima\SimpleQueue\Contract\JobData(
                    id: 123,
                    queue: 'default',
                    type: 'test',
                    status: JobStatus::Running,
                    payload: ['foo' => 'bar'],
                    attempts: 0,
                    maxAttempts: 3,
                    availableAt: date('Y-m-d H:i:s'),
                    startedAt: date('Y-m-d H:i:s'),
                    completedAt: null,
                    lockedBy: 'worker-1',
                    lockedAt: date('Y-m-d H:i:s'),
                    leaseToken: 'token-123'
                ),
                'worker-1',
                'token-123'
            ));

        $driver = new DatabaseQueueDriver($mockStorage, 50);
        $result = $driver->dequeue('default', 2);

        self::assertEquals(123, $result);
    }

    public function testSetWorkerIdIsPassedToStorage(): void
    {
        $mockStorage = $this->createMock(\Oeltima\SimpleQueue\Contract\JobStorageInterface::class);
        $mockStorage->expects($this->once())
            ->method('claimNextAvailable')
            ->with('default', 'custom-worker-id')
            ->willReturn(null);

        $driver = new DatabaseQueueDriver($mockStorage, 50);
        $driver->setWorkerId('custom-worker-id');
        $driver->dequeue('default', 0);
    }
}
