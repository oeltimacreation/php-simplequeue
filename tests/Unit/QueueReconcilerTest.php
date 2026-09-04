<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Unit;

use Oeltima\SimpleQueue\Contract\ClockInterface;
use Oeltima\SimpleQueue\Contract\JobStorageInterface;
use Oeltima\SimpleQueue\Contract\PendingNotification;
use Oeltima\SimpleQueue\Contract\QueueDriverInterface;
use Oeltima\SimpleQueue\Contract\SupportsBatchQueueReconciliation;
use Oeltima\SimpleQueue\Contract\SupportsBoundedQueueMembership;
use Oeltima\SimpleQueue\Contract\SupportsPendingJobCursor;
use Oeltima\SimpleQueue\Contract\SupportsPendingNotificationCursor;
use Oeltima\SimpleQueue\Driver\InMemoryQueueDriver;
use Oeltima\SimpleQueue\QueueReconciler;
use Oeltima\SimpleQueue\ReconcileOptions;
use Oeltima\SimpleQueue\Storage\InMemoryJobStorage;
use Oeltima\SimpleQueue\Tests\Support\FrozenClock;
use Oeltima\SimpleQueue\Tests\Support\JobDataFactory;
use PHPUnit\Framework\TestCase;

interface LegacyReconciliationStorage extends JobStorageInterface, SupportsPendingJobCursor
{
}

interface LeanReconciliationStorage extends JobStorageInterface, SupportsPendingNotificationCursor
{
}

interface ReconciliationMembershipDriver extends QueueDriverInterface, SupportsBoundedQueueMembership
{
}

interface BatchReconciliationDriver extends ReconciliationMembershipDriver, SupportsBatchQueueReconciliation
{
}

final class QueueReconcilerTest extends TestCase
{
    public function testCursorEventuallyVisitsOldestAndNewestRowsAcrossReconstruction(): void
    {
        $storage = new InMemoryJobStorage();
        for ($id = 0; $id < 1001; $id++) {
            $storage->createJob('test.job', [], 'default');
        }
        $driver = new InMemoryQueueDriver();

        $first = (new QueueReconciler($storage, $driver))->reconcile(
            'default',
            new ReconcileOptions(pageSize: 1000)
        );
        $second = (new QueueReconciler($storage, $driver))->reconcile(
            'default',
            new ReconcileOptions(cursor: $first->nextCursor, pageSize: 1000)
        );

        self::assertSame(1000, $first->scanned);
        self::assertSame(1000, $first->nextCursor);
        self::assertSame(1, $second->scanned);
        self::assertNull($second->nextCursor);
        self::assertContains(1, $driver->getPending('default'));
        self::assertContains(1001, $driver->getPending('default'));
    }

    public function testBoundedMembershipFalseNegativeCreatesDocumentedDuplicate(): void
    {
        $storage = new InMemoryJobStorage();
        $jobId = $storage->createJob('test.job', [], 'default');
        $driver = new InMemoryQueueDriver();
        $driver->enqueue('default', $jobId);
        for ($id = 2; $id <= 4; $id++) {
            $driver->enqueue('default', $id);
        }

        $result = (new QueueReconciler($storage, $driver))->reconcile(
            'default',
            new ReconcileOptions(membershipScanLimit: 1)
        );

        self::assertSame(1, $result->restored);
        self::assertCount(5, $driver->getPending('default'));
    }

    public function testDurationLimitResumesAfterLastProcessedJob(): void
    {
        $clock = new class implements ClockInterface {
            private int $reads = 0;

            public function now(): string
            {
                return '2023-11-14 22:13:20';
            }

            public function timestamp(): int
            {
                return 1_700_000_000;
            }

            public function monotonic(): float
            {
                return match ($this->reads++) {
                    0 => 0.0,
                    1 => 0.1,
                    default => 1.1,
                };
            }
        };
        $storage = new InMemoryJobStorage($clock);
        $storage->createJobs([
            ['type' => 'test.job', 'payload' => []],
            ['type' => 'test.job', 'payload' => []],
            ['type' => 'test.job', 'payload' => []],
        ]);
        $driver = new InMemoryQueueDriver($clock);
        $reconciler = new QueueReconciler($storage, $driver, $clock);

        $first = $reconciler->reconcile('default', new ReconcileOptions(pageSize: 3, maxDurationSeconds: 1.0));
        $second = $reconciler->reconcile(
            'default',
            new ReconcileOptions(cursor: $first->nextCursor, pageSize: 3, maxDurationSeconds: 1.0)
        );

        self::assertSame(1, $first->scanned);
        self::assertSame(1, $first->nextCursor);
        self::assertSame(2, $second->scanned);
        self::assertNull($second->nextCursor);
        self::assertSame([3, 2, 1], $driver->getPending('default'));
    }

    public function testReconcilesScheduledJobWithoutNotificationIntoDelayedStructure(): void
    {
        $this->assertReconcilesScheduledJobInTimezone('UTC', 60, 0, 1);
    }

    public function testReconciliationParsesStoredTimestampAsUtcWhenDefaultTimezoneIsBehindUtc(): void
    {
        $this->assertReconcilesScheduledJobInTimezone('America/New_York', -60, 1, 0);
    }

    public function testReconciliationParsesStoredTimestampAsUtcWhenDefaultTimezoneIsAheadOfUtc(): void
    {
        $this->assertReconcilesScheduledJobInTimezone('Asia/Tokyo', 60, 0, 1);
    }

    public function testLeanFallbackWrapsCursorAndRejectsInvalidTimestampWithoutQueueCalls(): void
    {
        $clock = new FrozenClock();
        $storage = $this->createMock(LeanReconciliationStorage::class);
        $storage->expects($this->exactly(2))
            ->method('scanPendingNotifications')
            ->willReturnOnConsecutiveCalls(
                [],
                [
                    new PendingNotification(1, $clock->now()),
                    new PendingNotification(2, null),
                    new PendingNotification(3, 'tomorrow'),
                ]
            );
        $driver = $this->createMock(ReconciliationMembershipDriver::class);
        $driver->expects($this->once())->method('hasPendingJob')->with('default', 1, 250)->willReturn(false);
        $driver->expects($this->once())->method('hasDelayedJob')->with('default', 1)->willReturn(false);
        $driver->expects($this->once())->method('enqueue')->with('default', 1);
        $driver->expects($this->never())->method('nack');

        $result = (new QueueReconciler($storage, $driver, $clock))->reconcile(
            'default',
            new ReconcileOptions(cursor: 99)
        );

        self::assertSame(3, $result->scanned);
        self::assertSame(1, $result->restored);
        self::assertSame(2, $result->invalid);
        self::assertNull($result->nextCursor);
    }

    public function testLegacyFullJobCursorRemainsSupported(): void
    {
        $clock = new FrozenClock();
        $storage = $this->createMock(LegacyReconciliationStorage::class);
        $storage->expects($this->exactly(2))
            ->method('scanPending')
            ->willReturnOnConsecutiveCalls(
                [],
                [
                    JobDataFactory::running(['id' => 1, 'availableAt' => $clock->now()]),
                    JobDataFactory::running(['id' => 2, 'availableAt' => '2099-01-01 00:00:00']),
                    JobDataFactory::running(['id' => 0, 'availableAt' => $clock->now()]),
                ]
            );
        $driver = $this->createMock(ReconciliationMembershipDriver::class);
        $driver->expects($this->exactly(2))
            ->method('hasPendingJob')
            ->willReturnMap([
                ['default', 1, 250, true],
                ['default', 2, 250, false],
            ]);
        $driver->expects($this->once())->method('hasDelayedJob')->with('default', 2)->willReturn(false);
        $driver->expects($this->once())->method('nack')->with('default', 2, self::greaterThan(0));

        $result = (new QueueReconciler($storage, $driver, $clock))->reconcile(
            'default',
            new ReconcileOptions(cursor: 99)
        );

        self::assertSame(3, $result->scanned);
        self::assertSame(1, $result->restored);
        self::assertSame(1, $result->duplicates);
        self::assertSame(1, $result->invalid);
        self::assertNull($result->nextCursor);
    }

    public function testBatchReconciliationFiltersInvalidNotificationsAndCountsDuplicates(): void
    {
        $clock = new FrozenClock();
        $storage = $this->createMock(LeanReconciliationStorage::class);
        $storage->expects($this->once())
            ->method('scanPendingNotifications')
            ->with('default', null, 100)
            ->willReturn([
                new PendingNotification(0, $clock->now()),
                new PendingNotification(1, null),
                new PendingNotification(2, $clock->now()),
                new PendingNotification(3, '2099-01-01 00:00:00'),
                new PendingNotification(4, 'next Monday'),
            ]);
        $driver = $this->createMock(BatchReconciliationDriver::class);
        $driver->expects($this->once())
            ->method('reconcileNotifications')
            ->with(
                'default',
                [2 => $clock->timestamp(), 3 => strtotime('2099-01-01 00:00:00 UTC')],
                $clock->timestamp(),
                250
            )
            ->willReturn([3]);

        $result = (new QueueReconciler($storage, $driver, $clock))->reconcile('default', new ReconcileOptions());

        self::assertSame(5, $result->scanned);
        self::assertSame(1, $result->restored);
        self::assertSame(1, $result->duplicates);
        self::assertSame(3, $result->invalid);
        self::assertNull($result->nextCursor);
    }

    public function testUnsupportedCapabilitiesFailFast(): void
    {
        $plainStorage = $this->createMock(JobStorageInterface::class);
        $plainDriver = $this->createMock(QueueDriverInterface::class);

        try {
            (new QueueReconciler($plainStorage, $plainDriver))->reconcile('default', new ReconcileOptions());
            self::fail('Storage capability check must fail');
        } catch (\LogicException $exception) {
            self::assertSame('Storage does not support bounded reconciliation', $exception->getMessage());
        }

        $storage = $this->createMock(LeanReconciliationStorage::class);
        try {
            (new QueueReconciler($storage, $plainDriver))->reconcile('default', new ReconcileOptions());
            self::fail('Driver capability check must fail');
        } catch (\LogicException $exception) {
            self::assertSame('Driver does not support bounded reconciliation', $exception->getMessage());
        }
    }

    private function assertReconcilesScheduledJobInTimezone(
        string $timezone,
        int $availableOffset,
        int $expectedPending,
        int $expectedDelayed
    ): void {
        $this->withTimezone($timezone, function () use ($availableOffset, $expectedPending, $expectedDelayed): void {
            $clock = new FrozenClock();
            $storage = new InMemoryJobStorage($clock);
            $driver = new InMemoryQueueDriver($clock);
            $storage->createJobs([
                ['type' => 'test.job', 'payload' => [], 'queue' => 'default', 'availableAt' => $clock->timestamp() + $availableOffset],
            ]);

            $result = (new QueueReconciler($storage, $driver, $clock))->reconcile('default', new ReconcileOptions());

            self::assertSame(1, $result->restored);
            self::assertSame($expectedPending, $driver->getPendingCount('default'));
            self::assertSame($expectedDelayed, $driver->getDelayedCount('default'));

            if ($expectedDelayed > 0) {
                $clock->advance($availableOffset);
                self::assertSame(1, $driver->promoteDelayedJobs('default'));
                self::assertSame(1, $driver->getPendingCount('default'));
            }
        });
    }

    private function withTimezone(string $timezone, callable $fn): void
    {
        $previousTimezone = date_default_timezone_get();
        date_default_timezone_set($timezone);
        try {
            $fn();
        } finally {
            date_default_timezone_set($previousTimezone);
        }
    }
}
