<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Unit;

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
}
