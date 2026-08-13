<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Integration;

use Oeltima\SimpleQueue\AdminManager;
use Oeltima\SimpleQueue\Contract\QueueDriverInterface;
use Oeltima\SimpleQueue\Contract\SupportsJobRemoval;
use Oeltima\SimpleQueue\Driver\InMemoryQueueDriver;
use Oeltima\SimpleQueue\Exception\QueueException;
use Oeltima\SimpleQueue\QueueManager;
use Oeltima\SimpleQueue\Storage\InMemoryJobStorage;
use Oeltima\SimpleQueue\Tests\Support\FrozenClock;
use PHPUnit\Framework\TestCase;

final class FailedJobAdministrationTest extends TestCase
{
    public function testRequeueNotificationFailureLeavesPendingJobForRepair(): void
    {
        $storage = new InMemoryJobStorage(new FrozenClock());
        $jobId = $this->failedJob($storage);
        $inner = new InMemoryQueueDriver();
        $driver = $this->faultInjectingDriver($inner, true, false);
        $admin = new AdminManager($storage, new QueueManager($driver));

        $this->expectException(QueueException::class);
        $this->expectExceptionMessage('Failed job was re-queued but queue notification failed');

        try {
            $admin->requeueFailed($jobId);
        } finally {
            self::assertSame('pending', $storage->find($jobId)?->status->value);
            self::assertSame([], $inner->getPending('default'));
        }
    }

    public function testPurgeNotificationFailureReportsDurablePurge(): void
    {
        $storage = new InMemoryJobStorage(new FrozenClock());
        $jobId = $this->failedJob($storage);
        $inner = new InMemoryQueueDriver();
        $inner->enqueue('default', $jobId);
        self::assertSame($jobId, $inner->dequeue('default', 0));
        $driver = $this->faultInjectingDriver($inner, false, true);
        $admin = new AdminManager($storage, new QueueManager($driver));

        $this->expectException(QueueException::class);
        $this->expectExceptionMessage('Failed job was purged but queue notification cleanup failed');

        try {
            $admin->purgeFailed($jobId);
        } finally {
            self::assertNull($storage->find($jobId));
            self::assertSame([$jobId], $inner->getProcessing('default'));
        }
    }

    private function failedJob(InMemoryJobStorage $storage): int
    {
        $jobId = $storage->createJob('admin.failure', []);
        $claim = $storage->claimById($jobId, 'admin-worker');
        self::assertNotNull($claim);
        self::assertTrue($storage->markFailed($claim, 'permanent failure'));

        return $jobId;
    }

    private function faultInjectingDriver(
        InMemoryQueueDriver $inner,
        bool $failEnqueue,
        bool $failRemove
    ): QueueDriverInterface&SupportsJobRemoval {
        $driver = $this->createMockForIntersectionOfInterfaces([
            QueueDriverInterface::class,
            SupportsJobRemoval::class,
        ]);
        $driver->method('isAvailable')->willReturn(true);
        $driver->method('enqueue')->willReturnCallback(
            function (string $queue, int $jobId) use ($inner, $failEnqueue): void {
                if ($failEnqueue) {
                    throw new \RuntimeException('Injected enqueue failure');
                }
                $inner->enqueue($queue, $jobId);
            }
        );
        $driver->method('remove')->willReturnCallback(
            function (string $queue, int $jobId) use ($inner, $failRemove): void {
                if ($failRemove) {
                    throw new \RuntimeException('Injected remove failure');
                }
                $inner->remove($queue, $jobId);
            }
        );

        return $driver;
    }
}
