<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Unit;

use Oeltima\SimpleQueue\Contract\ClockInterface;
use Oeltima\SimpleQueue\Contract\JobStatus;
use Oeltima\SimpleQueue\Storage\InMemoryJobStorage;
use Oeltima\SimpleQueue\Exception\SerializationException;
use Oeltima\SimpleQueue\Tests\Support\FrozenClock;
use PHPUnit\Framework\TestCase;

class InMemoryStorageTest extends TestCase
{
    private InMemoryJobStorage $storage;

    protected function setUp(): void
    {
        $this->storage = new InMemoryJobStorage();
    }

    public function testCreateJobReturnsIncrementingIds(): void
    {
        $id1 = $this->storage->createJob('test.job', []);
        $id2 = $this->storage->createJob('test.job', []);
        $id3 = $this->storage->createJob('test.job', []);

        self::assertEquals(1, $id1);
        self::assertEquals(2, $id2);
        self::assertEquals(3, $id3);
    }

    public function testCreateJobRejectsUnserializablePayload(): void
    {
        $resource = fopen('php://memory', 'r');
        self::assertIsResource($resource);
        $this->expectException(SerializationException::class);
        try {
            $this->storage->createJob('test.job', ['resource' => $resource]);
        } finally {
            fclose($resource);
        }
    }

    public function testCreateJobsBatch(): void
    {
        $jobs = [
            ['type' => 'test.job1', 'payload' => ['a' => 1], 'queue' => 'default', 'maxAttempts' => 3],
            ['type' => 'test.job2', 'payload' => ['b' => 2], 'queue' => 'default', 'maxAttempts' => 5],
        ];

        $ids = $this->storage->createJobs($jobs);
        self::assertCount(2, $ids);

        $job1 = $this->storage->find($ids[0]);
        self::assertNotNull($job1);
        self::assertEquals('test.job1', $job1->type);
        self::assertEquals(['a' => 1], $job1->payload);
        self::assertEquals(3, $job1->maxAttempts);

        $job2 = $this->storage->find($ids[1]);
        self::assertNotNull($job2);
        self::assertEquals('test.job2', $job2->type);
        self::assertEquals(['b' => 2], $job2->payload);
        self::assertEquals(5, $job2->maxAttempts);
    }

    public function testFindReturnsJobData(): void
    {
        $id = $this->storage->createJob('test.job', ['key' => 'value']);

        $job = $this->storage->find($id);

        self::assertNotNull($job);
        self::assertEquals($id, $job->id);
        self::assertEquals('test.job', $job->type);
        self::assertSame(JobStatus::Pending, $job->status);
        self::assertEquals(['key' => 'value'], $job->payload);
    }

    public function testCreateJobUsesInjectedClock(): void
    {
        $clock = new FrozenClock(1_767_323_045);
        $storage = new InMemoryJobStorage($clock);

        $id = $storage->createJob('test.job', []);
        $job = $storage->find($id);

        self::assertNotNull($job);
        self::assertEquals('2026-01-02 03:04:05', $job->createdAt);
        self::assertEquals('2026-01-02 03:04:05', $job->updatedAt);
    }

    public function testFindReturnsNullForNonExistent(): void
    {
        $job = $this->storage->find(99999);

        self::assertNull($job);
    }

    public function testClaimNextAvailableReturnsFirstPending(): void
    {
        $id1 = $this->storage->createJob('test.job', [], 'default');
        $this->storage->createJob('test.job', [], 'default');

        $claim = $this->storage->claimNextAvailable('default', 'worker-1');

        self::assertNotNull($claim);
        self::assertEquals($id1, $claim->job->id);
    }

    public function testClaimNextAvailableReturnsNullWhenEmpty(): void
    {
        $claim = $this->storage->claimNextAvailable('default', 'worker-1');

        self::assertNull($claim);
    }

    public function testClaimByIdChangesStatusToRunning(): void
    {
        $id = $this->storage->createJob('test.job', []);

        $claim = $this->storage->claimById($id, 'worker-1');

        self::assertNotNull($claim);

        $job = $this->storage->find($id);
        self::assertNotNull($job);
        self::assertSame(JobStatus::Running, $job->status);
        self::assertEquals('worker-1', $job->lockedBy);
        self::assertNotNull($job->lockedAt);
        self::assertNotNull($job->startedAt);
        self::assertNotNull($job->leaseToken);
    }

    public function testClaimByIdReturnsNullForNonPending(): void
    {
        $id = $this->storage->createJob('test.job', []);
        $this->storage->claimById($id, 'worker-1');

        // Try to claim again
        $claim = $this->storage->claimById($id, 'worker-2');

        self::assertNull($claim);
    }

    public function testClaimNextAvailableReturnsClaimedJob(): void
    {
        $id = $this->storage->createJob('test.job', [], 'default');

        $claim = $this->storage->claimNextAvailable('default', 'worker-1');

        self::assertNotNull($claim);
        self::assertSame($id, $claim->job->id);
        self::assertSame('worker-1', $claim->workerId);
        self::assertNotEmpty($claim->leaseToken);
        self::assertSame($claim->leaseToken, $claim->job->leaseToken);
        self::assertSame(JobStatus::Running, $claim->job->status);
    }

    public function testClaimNextAvailableSkipsOtherQueues(): void
    {
        $this->storage->createJob('test.job', [], 'emails');

        $claim = $this->storage->claimNextAvailable('default', 'worker-1');

        self::assertNull($claim);
    }

    public function testClaimByIdReturnsNullForUnavailableJob(): void
    {
        $id = $this->storage->createJob('test.job', []);

        self::assertNotNull($this->storage->claimById($id, 'worker-1'));
        self::assertNull($this->storage->claimById($id, 'worker-2'));
    }

    public function testReclaimFencesThePreviousLease(): void
    {
        $id = $this->storage->createJob('test.job', []);
        $firstClaim = $this->storage->claimById($id, 'worker-1');
        self::assertNotNull($firstClaim);
        $secondClaim = $this->storage->claimById($id, 'worker-1');
        self::assertNotNull($secondClaim);

        self::assertFalse($this->storage->markCompleted($firstClaim));
        self::assertTrue($this->storage->markCompleted($secondClaim));
        self::assertSame(JobStatus::Completed, $this->storage->find($id)?->status);
    }

    public function testMarkCompletedSetsStatusAndResult(): void
    {
        $id = $this->storage->createJob('test.job', []);
        $claim = $this->storage->claimById($id, 'worker-1');
        self::assertNotNull($claim);

        $completed = $this->storage->markCompleted($claim, ['result' => 'success']);

        self::assertTrue($completed);

        $job = $this->storage->find($id);
        self::assertNotNull($job);
        self::assertSame(JobStatus::Completed, $job->status);
        self::assertEquals(['result' => 'success'], $job->result);
        self::assertNotNull($job->completedAt);
    }

    public function testMarkFailedSetsStatusAndError(): void
    {
        $id = $this->storage->createJob('test.job', []);
        $claim = $this->storage->claimById($id, 'worker-1');
        self::assertNotNull($claim);

        $failed = $this->storage->markFailed($claim, 'Something went wrong', 'stack trace here');

        self::assertTrue($failed);

        $job = $this->storage->find($id);
        self::assertNotNull($job);
        self::assertSame(JobStatus::Failed, $job->status);
        self::assertEquals('Something went wrong', $job->errorMessage);
        self::assertEquals('stack trace here', $job->errorTrace);
    }

    public function testUpdateProgressSetsProgressAndMessage(): void
    {
        $id = $this->storage->createJob('test.job', []);
        $claim = $this->storage->claimById($id, 'worker-1');
        self::assertNotNull($claim);

        $updated = $this->storage->updateProgress($claim, 50, 'Halfway done');

        self::assertTrue($updated);

        $job = $this->storage->find($id);
        self::assertNotNull($job);
        self::assertEquals(50, $job->progress);
        self::assertEquals('Halfway done', $job->progressMessage);
    }

    public function testScheduleRetrySetsStatusToPending(): void
    {
        $id = $this->storage->createJob('test.job', []);
        $claim = $this->storage->claimById($id, 'worker-1');
        self::assertNotNull($claim);

        $scheduled = $this->storage->scheduleRetry($claim, 1, 5, 'Temporary error');

        self::assertTrue($scheduled);

        $job = $this->storage->find($id);
        self::assertNotNull($job);
        self::assertSame(JobStatus::Pending, $job->status);
        self::assertEquals(1, $job->attempts);
        self::assertNotNull($job->availableAt);
        self::assertEquals('Temporary error', $job->errorMessage);
        self::assertNull($job->lockedBy);
        self::assertNull($job->leaseToken);
    }

    public function testClearRemovesAllJobs(): void
    {
        $this->storage->createJob('test.job', []);
        $this->storage->createJob('test.job', []);
        $this->storage->createJob('test.job', []);

        $this->storage->clear();

        self::assertEmpty($this->storage->all());
    }

    public function testFindActiveByRequestIdReturnsPendingJob(): void
    {
        $id = $this->storage->createJob('test.job', [], 'default', 3, 'req-abc');

        $found = $this->storage->findActiveByRequestId('req-abc');

        self::assertNotNull($found);
        self::assertEquals($id, $found->id);
        self::assertEquals('req-abc', $found->requestId);
    }

    public function testFindActiveByRequestIdReturnsRunningJob(): void
    {
        $id = $this->storage->createJob('test.job', [], 'default', 3, 'req-def');
        $this->storage->claimById($id, 'worker-1');

        $found = $this->storage->findActiveByRequestId('req-def');

        self::assertNotNull($found);
        self::assertEquals($id, $found->id);
        self::assertSame(JobStatus::Running, $found->status);
    }

    public function testFindActiveByRequestIdReturnsNullForCompletedJob(): void
    {
        $id = $this->storage->createJob('test.job', [], 'default', 3, 'req-ghi');
        $claim = $this->storage->claimById($id, 'worker-1');
        self::assertNotNull($claim);
        $this->storage->markCompleted($claim);

        $found = $this->storage->findActiveByRequestId('req-ghi');

        self::assertNull($found);
    }

    public function testFindActiveByRequestIdReturnsNullForFailedJob(): void
    {
        $id = $this->storage->createJob('test.job', [], 'default', 3, 'req-jkl');
        $claim = $this->storage->claimById($id, 'worker-1');
        self::assertNotNull($claim);
        $this->storage->markFailed($claim, 'Error');

        $found = $this->storage->findActiveByRequestId('req-jkl');

        self::assertNull($found);
    }

    public function testFindActiveByRequestIdReturnsNullForNonExistentRequestId(): void
    {
        $found = $this->storage->findActiveByRequestId('non-existent');

        self::assertNull($found);
    }

    public function testListReturnsAllJobsWhenNoFilters(): void
    {
        $this->storage->createJob('test.job', [], 'default');
        $this->storage->createJob('test.job', [], 'emails');
        $this->storage->createJob('test.job', [], 'default');

        $jobs = $this->storage->list();

        self::assertCount(3, $jobs);
    }

    public function testListFiltersByStatus(): void
    {
        $id1 = $this->storage->createJob('test.job', []);
        $id2 = $this->storage->createJob('test.job', []);
        $this->storage->claimById($id1, 'worker-1');

        $pendingJobs = $this->storage->list(JobStatus::Pending);
        $runningJobs = $this->storage->list(JobStatus::Running);

        self::assertCount(1, $pendingJobs);
        self::assertCount(1, $runningJobs);
        self::assertEquals($id2, $pendingJobs[0]->id);
        self::assertEquals($id1, $runningJobs[0]->id);
    }

    public function testListFiltersByQueue(): void
    {
        $this->storage->createJob('test.job', [], 'default');
        $this->storage->createJob('test.job', [], 'emails');
        $this->storage->createJob('test.job', [], 'default');

        $defaultJobs = $this->storage->list(null, 'default');
        $emailJobs = $this->storage->list(null, 'emails');

        self::assertCount(2, $defaultJobs);
        self::assertCount(1, $emailJobs);
    }

    public function testListRespectsLimitAndOffset(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->storage->createJob('test.job', []);
        }

        $page1 = $this->storage->list(null, null, 2, 0);
        $page2 = $this->storage->list(null, null, 2, 2);

        self::assertCount(2, $page1);
        self::assertCount(2, $page2);
        self::assertNotEquals($page1[0]->id, $page2[0]->id);
    }

    public function testListReturnsNewestFirst(): void
    {
        $id1 = $this->storage->createJob('test.job', []);
        $id2 = $this->storage->createJob('test.job', []);
        $id3 = $this->storage->createJob('test.job', []);

        $jobs = $this->storage->list();

        self::assertEquals($id3, $jobs[0]->id);
        self::assertEquals($id2, $jobs[1]->id);
        self::assertEquals($id1, $jobs[2]->id);
    }

    public function testCountReturnsAllWhenNoFilters(): void
    {
        $this->storage->createJob('test.job', []);
        $this->storage->createJob('test.job', []);

        self::assertEquals(2, $this->storage->count());
    }

    public function testCountFiltersByStatus(): void
    {
        $id1 = $this->storage->createJob('test.job', []);
        $this->storage->createJob('test.job', []);
        $this->storage->claimById($id1, 'worker-1');

        self::assertEquals(1, $this->storage->count(JobStatus::Pending));
        self::assertEquals(1, $this->storage->count(JobStatus::Running));
        self::assertEquals(0, $this->storage->count(JobStatus::Completed));
    }

    public function testCountFiltersByQueue(): void
    {
        $this->storage->createJob('test.job', [], 'default');
        $this->storage->createJob('test.job', [], 'emails');

        self::assertEquals(1, $this->storage->count(null, 'default'));
        self::assertEquals(1, $this->storage->count(null, 'emails'));
    }

    public function testRecoverStaleJobsIncrementsAttempts(): void
    {
        $clock = $this->createMock(ClockInterface::class);
        $clock->expects($this->any())
            ->method('now')
            ->willReturnOnConsecutiveCalls(
                '2026-06-14 12:00:00',
                '2026-06-14 12:00:00',
                '2026-06-14 12:15:00',
                '2026-06-14 12:15:00'
            );
        $clock->method('timestamp')->willReturn(strtotime('2026-06-14 12:15:00 UTC'));

        $storage = new InMemoryJobStorage($clock);

        $id = $storage->createJob('test.job', [], 'default', 3);
        $claim = $storage->claimById($id, 'worker-1');
        self::assertNotNull($claim);

        $recovered = $storage->recoverStaleJobs(600);
        self::assertSame(1, $recovered);

        $job = $storage->find($id);
        self::assertNotNull($job);
        self::assertSame(JobStatus::Pending, $job->status);
        self::assertSame(1, $job->attempts);
        self::assertNull($job->lockedBy);
        self::assertNull($job->leaseToken);
    }

    public function testRecoverStaleJobsFailsPoisonJobs(): void
    {
        $clock = $this->createMock(ClockInterface::class);
        $clock->expects($this->any())
            ->method('now')
            ->willReturnOnConsecutiveCalls(
                '2026-06-14 12:00:00',
                '2026-06-14 12:00:00',
                '2026-06-14 12:15:00',
                '2026-06-14 12:15:00'
            );
        $clock->method('timestamp')->willReturn(strtotime('2026-06-14 12:15:00 UTC'));

        $storage = new InMemoryJobStorage($clock);

        $id = $storage->createJob('test.job', [], 'default', 1);
        $claim = $storage->claimById($id, 'worker-1');
        self::assertNotNull($claim);

        $recovered = $storage->recoverStaleJobs(600);
        self::assertSame(1, $recovered);

        $job = $storage->find($id);
        self::assertNotNull($job);
        self::assertSame(JobStatus::Failed, $job->status);
        self::assertSame('Job timed out / worker crashed (stale recovery)', $job->errorMessage);
        self::assertNull($job->lockedBy);
        self::assertNull($job->leaseToken);
    }

    public function testCancelPendingJob(): void
    {
        $id = $this->storage->createJob('test.job', []);

        $result = $this->storage->cancel($id);

        self::assertTrue($result);
        $job = $this->storage->find($id);
        self::assertNotNull($job);
        self::assertSame(JobStatus::Cancelled, $job->status);
        self::assertNotNull($job->completedAt);
        self::assertNull($job->lockedBy);
        self::assertNull($job->leaseToken);
    }

    public function testCancelNonPendingJobFails(): void
    {
        $id = $this->storage->createJob('test.job', []);
        $claim = $this->storage->claimById($id, 'worker-1');
        self::assertNotNull($claim);

        $result = $this->storage->cancel($id);

        self::assertFalse($result);
        $job = $this->storage->find($id);
        self::assertNotNull($job);
        self::assertSame(JobStatus::Running, $job->status);
    }

    public function testCancelNonExistentJobFails(): void
    {
        $result = $this->storage->cancel(9999);
        self::assertFalse($result);
    }

    public function testFullPendingCursorIsOrderedBoundedAndQueueScoped(): void
    {
        $storage = new InMemoryJobStorage(new FrozenClock());
        $ids = $storage->createJobs([
            ['type' => 'first.job', 'payload' => [], 'queue' => 'alpha'],
            ['type' => 'other.job', 'payload' => [], 'queue' => 'beta'],
            ['type' => 'second.job', 'payload' => [], 'queue' => 'alpha'],
            ['type' => 'third.job', 'payload' => [], 'queue' => 'alpha'],
        ]);

        $page = $storage->scanPending('alpha', $ids[0], 2);

        self::assertSame([$ids[2], $ids[3]], array_column($page, 'id'));

        $claim = $storage->claimById($ids[2], 'worker-1');
        self::assertNotNull($claim);
        self::assertTrue($storage->markCompleted($claim));
        self::assertSame([$ids[0], $ids[3]], array_column($storage->scanPending('alpha', null, 10), 'id'));
    }

    public function testPruneCompletedDeletesOnlyExpiredCompletedAndCancelledJobs(): void
    {
        $clock = new FrozenClock();
        $storage = new InMemoryJobStorage($clock);
        $completedId = $storage->createJob('completed.job', []);
        $cancelledId = $storage->createJob('cancelled.job', []);
        $pendingId = $storage->createJob('pending.job', []);
        $claim = $storage->claimById($completedId, 'worker-1');
        self::assertNotNull($claim);
        self::assertTrue($storage->markCompleted($claim));
        self::assertTrue($storage->cancel($cancelledId));
        $clock->advance(2 * 86400);

        self::assertSame(2, $storage->pruneCompleted(1));
        self::assertNull($storage->find($completedId));
        self::assertNull($storage->find($cancelledId));
        self::assertNotNull($storage->find($pendingId));
    }
}
