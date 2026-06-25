<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Unit;

use Oeltima\SimpleQueue\Contract\ClockInterface;
use Oeltima\SimpleQueue\Contract\JobStatus;
use Oeltima\SimpleQueue\Storage\InMemoryJobStorage;
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

        $this->assertEquals(1, $id1);
        $this->assertEquals(2, $id2);
        $this->assertEquals(3, $id3);
    }

    public function testCreateJobsBatch(): void
    {
        $jobs = [
            ['type' => 'test.job1', 'payload' => ['a' => 1], 'queue' => 'default', 'maxAttempts' => 3],
            ['type' => 'test.job2', 'payload' => ['b' => 2], 'queue' => 'default', 'maxAttempts' => 5],
        ];

        $ids = $this->storage->createJobs($jobs);
        $this->assertCount(2, $ids);

        $job1 = $this->storage->find($ids[0]);
        $this->assertNotNull($job1);
        $this->assertEquals('test.job1', $job1->type);
        $this->assertEquals(['a' => 1], $job1->payload);
        $this->assertEquals(3, $job1->maxAttempts);

        $job2 = $this->storage->find($ids[1]);
        $this->assertNotNull($job2);
        $this->assertEquals('test.job2', $job2->type);
        $this->assertEquals(['b' => 2], $job2->payload);
        $this->assertEquals(5, $job2->maxAttempts);
    }

    public function testFindReturnsJobData(): void
    {
        $id = $this->storage->createJob('test.job', ['key' => 'value']);

        $job = $this->storage->find($id);

        $this->assertNotNull($job);
        $this->assertEquals($id, $job->id);
        $this->assertEquals('test.job', $job->type);
        $this->assertSame(JobStatus::Pending, $job->status);
        $this->assertEquals(['key' => 'value'], $job->payload);
    }

    public function testCreateJobUsesInjectedClock(): void
    {
        $clock = new class implements ClockInterface {
            public function now(): string
            {
                return '2026-01-02 03:04:05';
            }

            public function timestamp(): int
            {
                return 1767323045;
            }

            public function monotonic(): float
            {
                return 1.0;
            }
        };
        $storage = new InMemoryJobStorage($clock);

        $id = $storage->createJob('test.job', []);
        $job = $storage->find($id);

        $this->assertEquals('2026-01-02 03:04:05', $job->createdAt);
        $this->assertEquals('2026-01-02 03:04:05', $job->updatedAt);
    }

    public function testFindReturnsNullForNonExistent(): void
    {
        $job = $this->storage->find(99999);

        $this->assertNull($job);
    }

    public function testClaimNextAvailableReturnsFirstPending(): void
    {
        $id1 = $this->storage->createJob('test.job', [], 'default');
        $this->storage->createJob('test.job', [], 'default');

        $claim = $this->storage->claimNextAvailable('default', 'worker-1');

        $this->assertNotNull($claim);
        $this->assertEquals($id1, $claim->job->id);
    }

    public function testClaimNextAvailableReturnsNullWhenEmpty(): void
    {
        $claim = $this->storage->claimNextAvailable('default', 'worker-1');

        $this->assertNull($claim);
    }

    public function testClaimByIdChangesStatusToRunning(): void
    {
        $id = $this->storage->createJob('test.job', []);

        $claim = $this->storage->claimById($id, 'worker-1');

        $this->assertNotNull($claim);

        $job = $this->storage->find($id);
        $this->assertSame(JobStatus::Running, $job->status);
        $this->assertEquals('worker-1', $job->lockedBy);
        $this->assertNotNull($job->lockedAt);
        $this->assertNotNull($job->startedAt);
        $this->assertNotNull($job->leaseToken);
    }

    public function testClaimByIdReturnsNullForNonPending(): void
    {
        $id = $this->storage->createJob('test.job', []);
        $this->storage->claimById($id, 'worker-1');

        // Try to claim again
        $claim = $this->storage->claimById($id, 'worker-2');

        $this->assertNull($claim);
    }

    public function testClaimNextAvailableReturnsClaimedJob(): void
    {
        $id = $this->storage->createJob('test.job', [], 'default');

        $claim = $this->storage->claimNextAvailable('default', 'worker-1');

        $this->assertNotNull($claim);
        $this->assertSame($id, $claim->job->id);
        $this->assertSame('worker-1', $claim->workerId);
        $this->assertNotEmpty($claim->leaseToken);
        $this->assertSame($claim->leaseToken, $claim->job->leaseToken);
        $this->assertSame(JobStatus::Running, $claim->job->status);
    }

    public function testClaimNextAvailableSkipsOtherQueues(): void
    {
        $this->storage->createJob('test.job', [], 'emails');

        $claim = $this->storage->claimNextAvailable('default', 'worker-1');

        $this->assertNull($claim);
    }

    public function testClaimByIdReturnsNullForUnavailableJob(): void
    {
        $id = $this->storage->createJob('test.job', []);

        $this->assertNotNull($this->storage->claimById($id, 'worker-1'));
        $this->assertNull($this->storage->claimById($id, 'worker-2'));
    }

    public function testMarkCompletedSetsStatusAndResult(): void
    {
        $id = $this->storage->createJob('test.job', []);
        $claim = $this->storage->claimById($id, 'worker-1');
        $this->assertNotNull($claim);

        $completed = $this->storage->markCompleted($claim, ['result' => 'success']);

        $this->assertTrue($completed);

        $job = $this->storage->find($id);
        $this->assertSame(JobStatus::Completed, $job->status);
        $this->assertEquals(['result' => 'success'], $job->result);
        $this->assertNotNull($job->completedAt);
    }

    public function testMarkFailedSetsStatusAndError(): void
    {
        $id = $this->storage->createJob('test.job', []);
        $claim = $this->storage->claimById($id, 'worker-1');
        $this->assertNotNull($claim);

        $failed = $this->storage->markFailed($claim, 'Something went wrong', 'stack trace here');

        $this->assertTrue($failed);

        $job = $this->storage->find($id);
        $this->assertSame(JobStatus::Failed, $job->status);
        $this->assertEquals('Something went wrong', $job->errorMessage);
        $this->assertEquals('stack trace here', $job->errorTrace);
    }

    public function testUpdateProgressSetsProgressAndMessage(): void
    {
        $id = $this->storage->createJob('test.job', []);
        $claim = $this->storage->claimById($id, 'worker-1');
        $this->assertNotNull($claim);

        $updated = $this->storage->updateProgress($claim, 50, 'Halfway done');

        $this->assertTrue($updated);

        $job = $this->storage->find($id);
        $this->assertEquals(50, $job->progress);
        $this->assertEquals('Halfway done', $job->progressMessage);
    }

    public function testScheduleRetrySetsStatusToPending(): void
    {
        $id = $this->storage->createJob('test.job', []);
        $claim = $this->storage->claimById($id, 'worker-1');
        $this->assertNotNull($claim);

        $scheduled = $this->storage->scheduleRetry($claim, 1, 5, 'Temporary error');

        $this->assertTrue($scheduled);

        $job = $this->storage->find($id);
        $this->assertSame(JobStatus::Pending, $job->status);
        $this->assertEquals(1, $job->attempts);
        $this->assertNotNull($job->availableAt);
        $this->assertEquals('Temporary error', $job->errorMessage);
        $this->assertNull($job->lockedBy);
        $this->assertNull($job->leaseToken);
    }

    public function testClearRemovesAllJobs(): void
    {
        $this->storage->createJob('test.job', []);
        $this->storage->createJob('test.job', []);
        $this->storage->createJob('test.job', []);

        $this->storage->clear();

        $this->assertEmpty($this->storage->all());
    }

    public function testFindActiveByRequestIdReturnsPendingJob(): void
    {
        $id = $this->storage->createJob('test.job', [], 'default', 3, 'req-abc');

        $found = $this->storage->findActiveByRequestId('req-abc');

        $this->assertNotNull($found);
        $this->assertEquals($id, $found->id);
        $this->assertEquals('req-abc', $found->requestId);
    }

    public function testFindActiveByRequestIdReturnsRunningJob(): void
    {
        $id = $this->storage->createJob('test.job', [], 'default', 3, 'req-def');
        $this->storage->claimById($id, 'worker-1');

        $found = $this->storage->findActiveByRequestId('req-def');

        $this->assertNotNull($found);
        $this->assertEquals($id, $found->id);
        $this->assertSame(JobStatus::Running, $found->status);
    }

    public function testFindActiveByRequestIdReturnsNullForCompletedJob(): void
    {
        $id = $this->storage->createJob('test.job', [], 'default', 3, 'req-ghi');
        $claim = $this->storage->claimById($id, 'worker-1');
        $this->assertNotNull($claim);
        $this->storage->markCompleted($claim);

        $found = $this->storage->findActiveByRequestId('req-ghi');

        $this->assertNull($found);
    }

    public function testFindActiveByRequestIdReturnsNullForFailedJob(): void
    {
        $id = $this->storage->createJob('test.job', [], 'default', 3, 'req-jkl');
        $claim = $this->storage->claimById($id, 'worker-1');
        $this->assertNotNull($claim);
        $this->storage->markFailed($claim, 'Error');

        $found = $this->storage->findActiveByRequestId('req-jkl');

        $this->assertNull($found);
    }

    public function testFindActiveByRequestIdReturnsNullForNonExistentRequestId(): void
    {
        $found = $this->storage->findActiveByRequestId('non-existent');

        $this->assertNull($found);
    }

    public function testListReturnsAllJobsWhenNoFilters(): void
    {
        $this->storage->createJob('test.job', [], 'default');
        $this->storage->createJob('test.job', [], 'emails');
        $this->storage->createJob('test.job', [], 'default');

        $jobs = $this->storage->list();

        $this->assertCount(3, $jobs);
    }

    public function testListFiltersByStatus(): void
    {
        $id1 = $this->storage->createJob('test.job', []);
        $id2 = $this->storage->createJob('test.job', []);
        $this->storage->claimById($id1, 'worker-1');

        $pendingJobs = $this->storage->list(JobStatus::Pending);
        $runningJobs = $this->storage->list(JobStatus::Running);

        $this->assertCount(1, $pendingJobs);
        $this->assertCount(1, $runningJobs);
        $this->assertEquals($id2, $pendingJobs[0]->id);
        $this->assertEquals($id1, $runningJobs[0]->id);
    }

    public function testListFiltersByQueue(): void
    {
        $this->storage->createJob('test.job', [], 'default');
        $this->storage->createJob('test.job', [], 'emails');
        $this->storage->createJob('test.job', [], 'default');

        $defaultJobs = $this->storage->list(null, 'default');
        $emailJobs = $this->storage->list(null, 'emails');

        $this->assertCount(2, $defaultJobs);
        $this->assertCount(1, $emailJobs);
    }

    public function testListRespectsLimitAndOffset(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->storage->createJob('test.job', []);
        }

        $page1 = $this->storage->list(null, null, 2, 0);
        $page2 = $this->storage->list(null, null, 2, 2);

        $this->assertCount(2, $page1);
        $this->assertCount(2, $page2);
        $this->assertNotEquals($page1[0]->id, $page2[0]->id);
    }

    public function testListReturnsNewestFirst(): void
    {
        $id1 = $this->storage->createJob('test.job', []);
        $id2 = $this->storage->createJob('test.job', []);
        $id3 = $this->storage->createJob('test.job', []);

        $jobs = $this->storage->list();

        $this->assertEquals($id3, $jobs[0]->id);
        $this->assertEquals($id2, $jobs[1]->id);
        $this->assertEquals($id1, $jobs[2]->id);
    }

    public function testCountReturnsAllWhenNoFilters(): void
    {
        $this->storage->createJob('test.job', []);
        $this->storage->createJob('test.job', []);

        $this->assertEquals(2, $this->storage->count());
    }

    public function testCountFiltersByStatus(): void
    {
        $id1 = $this->storage->createJob('test.job', []);
        $this->storage->createJob('test.job', []);
        $this->storage->claimById($id1, 'worker-1');

        $this->assertEquals(1, $this->storage->count(JobStatus::Pending));
        $this->assertEquals(1, $this->storage->count(JobStatus::Running));
        $this->assertEquals(0, $this->storage->count(JobStatus::Completed));
    }

    public function testCountFiltersByQueue(): void
    {
        $this->storage->createJob('test.job', [], 'default');
        $this->storage->createJob('test.job', [], 'emails');

        $this->assertEquals(1, $this->storage->count(null, 'default'));
        $this->assertEquals(1, $this->storage->count(null, 'emails'));
    }

    public function testImplementsAdminInterface(): void
    {
        $this->assertInstanceOf(
            \Oeltima\SimpleQueue\Contract\JobStorageAdminInterface::class,
            $this->storage
        );
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

        $storage = new InMemoryJobStorage($clock);

        $id = $storage->createJob('test.job', [], 'default', 3);
        $claim = $storage->claimById($id, 'worker-1');
        $this->assertNotNull($claim);

        $recovered = $storage->recoverStaleJobs(600);
        $this->assertSame(1, $recovered);

        $job = $storage->find($id);
        $this->assertSame(JobStatus::Pending, $job->status);
        $this->assertSame(1, $job->attempts);
        $this->assertNull($job->lockedBy);
        $this->assertNull($job->leaseToken);
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

        $storage = new InMemoryJobStorage($clock);

        $id = $storage->createJob('test.job', [], 'default', 1);
        $claim = $storage->claimById($id, 'worker-1');
        $this->assertNotNull($claim);

        $recovered = $storage->recoverStaleJobs(600);
        $this->assertSame(1, $recovered);

        $job = $storage->find($id);
        $this->assertSame(JobStatus::Failed, $job->status);
        $this->assertSame('Job timed out / worker crashed (stale recovery)', $job->errorMessage);
        $this->assertNull($job->lockedBy);
        $this->assertNull($job->leaseToken);
    }

    public function testCancelPendingJob(): void
    {
        $id = $this->storage->createJob('test.job', []);

        $result = $this->storage->cancel($id);

        $this->assertTrue($result);
        $job = $this->storage->find($id);
        $this->assertNotNull($job);
        $this->assertSame(JobStatus::Cancelled, $job->status);
    }

    public function testCancelNonPendingJobFails(): void
    {
        $id = $this->storage->createJob('test.job', []);
        $claim = $this->storage->claimById($id, 'worker-1');
        $this->assertNotNull($claim);

        $result = $this->storage->cancel($id);

        $this->assertFalse($result);
        $job = $this->storage->find($id);
        $this->assertNotNull($job);
        $this->assertSame(JobStatus::Running, $job->status);
    }

    public function testCancelNonExistentJobFails(): void
    {
        $result = $this->storage->cancel(9999);
        $this->assertFalse($result);
    }
}
