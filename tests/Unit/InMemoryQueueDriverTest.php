<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Unit;

use Oeltima\SimpleQueue\Driver\InMemoryQueueDriver;
use Oeltima\SimpleQueue\Tests\Support\FrozenClock;
use PHPUnit\Framework\TestCase;

class InMemoryQueueDriverTest extends TestCase
{
    private InMemoryQueueDriver $driver;
    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock();
        $this->driver = new InMemoryQueueDriver($this->clock);
    }

    public function testEnqueueAddsJobToPending(): void
    {
        $this->driver->enqueue('default', 1);
        $this->driver->enqueue('default', 2);
        $this->driver->enqueue('default', 3);

        $pending = $this->driver->getPending('default');

        self::assertCount(3, $pending);
        self::assertContains(1, $pending);
        self::assertContains(2, $pending);
        self::assertContains(3, $pending);
    }

    public function testQueueViewsAndCountsShareMembershipState(): void
    {
        $this->driver->enqueue('default', 1);
        $this->driver->enqueue('default', 2);

        self::assertSame($this->driver->getPending('default'), $this->driver->getPendingIds('default'));
        self::assertSame(2, $this->driver->getPendingCount('default'));

        $this->driver->dequeue('default', 0);
        self::assertContains(1, $this->driver->getProcessing('default'));
        self::assertSame(1, $this->driver->getProcessingCount('default'));

        $this->driver->nack('default', 1, 60);
        self::assertSame(1, $this->driver->getDelayedCount('default'));
    }

    public function testBatchEnqueuePreservesExistingFifoOrder(): void
    {
        $this->driver->enqueue('default', 1);
        $this->driver->enqueueBatch('default', [2, 3, 4]);

        self::assertSame([1, 2, 3, 4], [
            $this->driver->dequeue('default', 0),
            $this->driver->dequeue('default', 0),
            $this->driver->dequeue('default', 0),
            $this->driver->dequeue('default', 0),
        ]);
    }

    public function testDequeueReturnsJobIdAndMovesToProcessing(): void
    {
        $this->driver->enqueue('default', 1);
        $this->driver->enqueue('default', 2);

        $jobId = $this->driver->dequeue('default', 0);

        // Should return first job (FIFO)
        self::assertEquals(1, $jobId);

        // Should be in processing now
        $processing = $this->driver->getProcessing('default');
        self::assertContains(1, $processing);

        // Should not be in pending anymore
        $pending = $this->driver->getPending('default');
        self::assertNotContains(1, $pending);
    }

    public function testRemoveDeletesAllNotificationStatesIdempotently(): void
    {
        $this->driver->enqueue('default', 7);
        $this->driver->dequeue('default', 0);
        $this->driver->nack('default', 7, 60);
        $this->driver->enqueue('default', 7);

        $this->driver->remove('default', 7);
        $this->driver->remove('default', 7);

        self::assertNotContains(7, $this->driver->getPending('default'));
        self::assertNotContains(7, $this->driver->getProcessing('default'));
        self::assertArrayNotHasKey(7, $this->driver->getDelayed('default'));
    }

    public function testHeartbeatProcessingRefreshesVisibilityTimestamp(): void
    {
        $this->driver->enqueue('default', 5);
        $this->driver->dequeue('default', 0);
        $this->clock->advance(500);
        $this->driver->heartbeatProcessing('default', 5);
        $this->clock->advance(200);

        self::assertSame(0, $this->driver->recoverStaleProcessing('default', 600));
        self::assertSame([5], $this->driver->getProcessing('default'));
    }

    public function testDequeueReturnsNullWhenEmpty(): void
    {
        $jobId = $this->driver->dequeue('default', 0);

        self::assertNull($jobId);
    }

    public function testAckRemovesFromProcessing(): void
    {
        $this->driver->enqueue('default', 1);
        $this->driver->dequeue('default', 0);

        $this->driver->ack('default', 1);

        $processing = $this->driver->getProcessing('default');
        self::assertNotContains(1, $processing);
    }

    public function testNackMovesBackToPending(): void
    {
        $this->driver->enqueue('default', 1);
        $this->driver->dequeue('default', 0);

        $this->driver->nack('default', 1);

        // Should not be in processing
        $processing = $this->driver->getProcessing('default');
        self::assertNotContains(1, $processing);

        // Should be back in pending
        $pending = $this->driver->getPending('default');
        self::assertContains(1, $pending);
    }

    public function testClearRemovesAllQueues(): void
    {
        $this->driver->enqueue('queue1', 1);
        $this->driver->enqueue('queue2', 2);
        $this->driver->dequeue('queue1', 0);

        $this->driver->clear();

        self::assertEmpty($this->driver->getPending('queue1'));
        self::assertEmpty($this->driver->getPending('queue2'));
        self::assertEmpty($this->driver->getProcessing('queue1'));
    }

    public function testQueuesAreIsolated(): void
    {
        $this->driver->enqueue('queue1', 1);
        $this->driver->enqueue('queue2', 2);

        $pending1 = $this->driver->getPending('queue1');
        $pending2 = $this->driver->getPending('queue2');

        self::assertContains(1, $pending1);
        self::assertNotContains(2, $pending1);

        self::assertContains(2, $pending2);
        self::assertNotContains(1, $pending2);
    }

    public function testNackWithDelayAddsToDelayed(): void
    {
        $this->driver->enqueue('default', 1);
        $this->driver->dequeue('default', 0);

        $this->driver->nack('default', 1, 60);

        $pending = $this->driver->getPending('default');
        self::assertNotContains(1, $pending);

        $delayed = $this->driver->getDelayed('default');
        self::assertArrayHasKey(1, $delayed);
    }

    public function testNackWithoutDelayReenqueuesImmediately(): void
    {
        $this->driver->enqueue('default', 1);
        $this->driver->dequeue('default', 0);

        $this->driver->nack('default', 1, 0);

        $pending = $this->driver->getPending('default');
        self::assertContains(1, $pending);

        $delayed = $this->driver->getDelayed('default');
        self::assertEmpty($delayed);
    }

    public function testEnqueueDelayedBatchAddsAllDelayedNotifications(): void
    {
        $this->driver->enqueueDelayedBatch('default', [1, 2, 3], 1_700_000_100);

        $delayed = $this->driver->getDelayed('default');
        self::assertSame(1_700_000_100, $delayed[1]);
        self::assertSame(1_700_000_100, $delayed[2]);
        self::assertSame(1_700_000_100, $delayed[3]);
        self::assertSame(0, $this->driver->getPendingCount('default'));
    }

    public function testPromoteDelayedJobsMovesToPending(): void
    {
        $this->driver->enqueueDelayed('default', 1, $this->clock->timestamp() + 60);
        $this->clock->advance(60);

        $count = $this->driver->promoteDelayedJobs('default');

        self::assertEquals(1, $count);
        self::assertContains(1, $this->driver->getPending('default'));
        self::assertEmpty($this->driver->getDelayed('default'));
    }

    public function testPromoteDelayedJobsIgnoresNotYetDue(): void
    {
        $this->driver->enqueue('default', 1);
        $this->driver->dequeue('default', 0);
        $this->driver->nack('default', 1, 3600);

        $count = $this->driver->promoteDelayedJobs('default');

        self::assertEquals(0, $count);
        self::assertNotContains(1, $this->driver->getPending('default'));
        self::assertArrayHasKey(1, $this->driver->getDelayed('default'));
    }

    public function testRecoverStaleProcessingMovesBackToPending(): void
    {
        $this->driver->enqueue('default', 1);
        $this->driver->dequeue('default', 0);
        $this->clock->advance(700);

        $count = $this->driver->recoverStaleProcessing('default', 600);

        self::assertEquals(1, $count);
        self::assertContains(1, $this->driver->getPending('default'));
        self::assertEmpty($this->driver->getProcessing('default'));
    }

    public function testRecoverStaleProcessingIgnoresRecentJobs(): void
    {
        $this->driver->enqueue('default', 1);
        $this->driver->dequeue('default', 0);

        $count = $this->driver->recoverStaleProcessing('default', 600);

        self::assertEquals(0, $count);
        self::assertContains(1, $this->driver->getProcessing('default'));
    }

    public function testClearResetsDelayedAndProcessingStartedAt(): void
    {
        $this->driver->enqueue('default', 1);
        $this->driver->dequeue('default', 0);
        $this->driver->nack('default', 1, 60);

        $this->driver->clear();

        self::assertEmpty($this->driver->getPending('default'));
        self::assertEmpty($this->driver->getProcessing('default'));
        self::assertEmpty($this->driver->getDelayed('default'));
    }

    public function testPromoteDelayedJobsRespectsLimit(): void
    {
        $availableAt = $this->clock->timestamp() + 60;
        $this->driver->enqueueDelayedBatch('default', [1, 2, 3], $availableAt);
        $this->clock->advance(60);

        // Limit to 2
        $count = $this->driver->promoteDelayedJobs('default', 2);

        self::assertEquals(2, $count);
        self::assertCount(2, $this->driver->getPending('default'));
        self::assertCount(1, $this->driver->getDelayed('default'));
    }

    public function testRecoverStaleProcessingRespectsLimit(): void
    {
        // Add 3 processing jobs
        $this->driver->enqueue('default', 1);
        $this->driver->dequeue('default', 0);
        $this->driver->enqueue('default', 2);
        $this->driver->dequeue('default', 0);
        $this->driver->enqueue('default', 3);
        $this->driver->dequeue('default', 0);
        $this->clock->advance(700);

        // Limit to 2
        $count = $this->driver->recoverStaleProcessing('default', 600, 2);

        self::assertEquals(2, $count);
        self::assertCount(2, $this->driver->getPending('default'));
        self::assertCount(1, $this->driver->getProcessing('default'));
    }

    public function testAcknowledgingOneDuplicatePreservesRecoveryForTheOther(): void
    {
        $this->driver->enqueueBatch('default', [7, 7]);
        self::assertSame(7, $this->driver->dequeue('default', 0));
        self::assertSame(7, $this->driver->dequeue('default', 0));
        $this->clock->advance(601);

        $this->driver->ack('default', 7);

        self::assertSame([7], $this->driver->getProcessing('default'));
        self::assertSame(1, $this->driver->recoverStaleProcessing('default', 600));
        self::assertSame([7], $this->driver->getPending('default'));
    }

    public function testRecoveringOneDuplicateRebasesTheRemainingRecoveryTimestamp(): void
    {
        $this->driver->enqueueBatch('default', [8, 8]);
        $this->driver->dequeue('default', 0);
        $this->driver->dequeue('default', 0);
        $this->clock->advance(601);

        self::assertSame(1, $this->driver->recoverStaleProcessing('default', 600));
        self::assertSame([8], $this->driver->getProcessing('default'));
        self::assertSame(0, $this->driver->recoverStaleProcessing('default', 600));
    }

    public function testReconciliationValidatesWholePageBeforeMutation(): void
    {
        try {
            $this->driver->reconcileNotifications(
                'default',
                [1 => $this->clock->timestamp(), 2 => 0],
                $this->clock->timestamp(),
                250
            );
            self::fail('Invalid reconciliation page must fail');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        self::assertSame([], $this->driver->getPending('default'));
        self::assertSame([], $this->driver->getDelayed('default'));
    }

    public function testMembershipAndReconciliationRequirePositiveIdsAndCurrentTime(): void
    {
        foreach (
            [
                fn () => $this->driver->hasPendingJob('default', 0, 1),
                fn () => $this->driver->hasDelayedJob('default', 0),
                fn () => $this->driver->reconcileNotifications('default', [], 0, 1),
            ] as $operation
        ) {
            try {
                $operation();
                self::fail('Invalid queue boundary must fail');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testReconciliationPairValidationRejectsEveryInvalidShape(): void
    {
        $validation = new \ReflectionMethod(InMemoryQueueDriver::class, 'validateReconciliationPair');
        $validation->invoke($this->driver, 1, $this->clock->timestamp());
        foreach ([['bad', 1], [0, 1], [1, 'bad'], [1, 0]] as [$jobId, $availableAt]) {
            try {
                $validation->invoke($this->driver, $jobId, $availableAt);
                self::fail('Invalid reconciliation pair must fail');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
