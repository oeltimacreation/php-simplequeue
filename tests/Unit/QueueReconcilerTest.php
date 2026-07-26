<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Unit;

use Oeltima\SimpleQueue\Contract\ClockInterface;
use Oeltima\SimpleQueue\Driver\InMemoryQueueDriver;
use Oeltima\SimpleQueue\QueueReconciler;
use Oeltima\SimpleQueue\ReconcileOptions;
use Oeltima\SimpleQueue\Storage\InMemoryJobStorage;
use PHPUnit\Framework\TestCase;

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

        $this->assertSame(1000, $first->scanned);
        $this->assertSame(1000, $first->nextCursor);
        $this->assertSame(1, $second->scanned);
        $this->assertNull($second->nextCursor);
        $this->assertContains(1, $driver->getPending('default'));
        $this->assertContains(1001, $driver->getPending('default'));
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

        $this->assertSame(1, $result->restored);
        $this->assertCount(5, $driver->getPending('default'));
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

        $this->assertSame(1, $first->scanned);
        $this->assertSame(1, $first->nextCursor);
        $this->assertSame(2, $second->scanned);
        $this->assertNull($second->nextCursor);
        $this->assertSame([3, 2, 1], $driver->getPending('default'));
    }
}
