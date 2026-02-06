<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Unit;

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

    public function testFindReturnsJobData(): void
    {
        $id = $this->storage->createJob('test.job', ['key' => 'value']);

        $job = $this->storage->find($id);

        $this->assertNotNull($job);
        $this->assertEquals($id, $job->id);
        $this->assertEquals('test.job', $job->type);
        $this->assertEquals('pending', $job->status);
        $this->assertEquals(['key' => 'value'], $job->payload);
    }

    public function testFindReturnsNullForNonExistent(): void
    {
        $job = $this->storage->find(99999);

        $this->assertNull($job);
    }

    public function testGetNextPendingJobIdReturnsFirstPending(): void
    {
        $id1 = $this->storage->createJob('test.job', [], 'default');
        $id2 = $this->storage->createJob('test.job', [], 'default');

        $nextId = $this->storage->getNextPendingJobId('default');

        $this->assertEquals($id1, $nextId);
    }

    public function testGetNextPendingJobIdReturnsNullWhenEmpty(): void
    {
        $nextId = $this->storage->getNextPendingJobId('default');

        $this->assertNull($nextId);
    }

    public function testClaimJobChangesStatusToRunning(): void
    {
        $id = $this->storage->createJob('test.job', []);

        $claimed = $this->storage->claimJob($id, 'worker-1');

        $this->assertTrue($claimed);

        $job = $this->storage->find($id);
        $this->assertEquals('running', $job->status);
        $this->assertEquals('worker-1', $job->lockedBy);
        $this->assertNotNull($job->lockedAt);
        $this->assertNotNull($job->startedAt);
    }

    public function testClaimJobReturnsFalseForNonPending(): void
    {
        $id = $this->storage->createJob('test.job', []);
        $this->storage->claimJob($id, 'worker-1');

        // Try to claim again
        $claimed = $this->storage->claimJob($id, 'worker-2');

        $this->assertFalse($claimed);
    }

    public function testMarkCompletedSetsStatusAndResult(): void
    {
        $id = $this->storage->createJob('test.job', []);
        $this->storage->claimJob($id, 'worker-1');

        $completed = $this->storage->markCompleted($id, ['result' => 'success']);

        $this->assertTrue($completed);

        $job = $this->storage->find($id);
        $this->assertEquals('completed', $job->status);
        $this->assertEquals(['result' => 'success'], $job->result);
        $this->assertNotNull($job->completedAt);
    }

    public function testMarkFailedSetsStatusAndError(): void
    {
        $id = $this->storage->createJob('test.job', []);
        $this->storage->claimJob($id, 'worker-1');

        $failed = $this->storage->markFailed($id, 'Something went wrong', 'stack trace here');

        $this->assertTrue($failed);

        $job = $this->storage->find($id);
        $this->assertEquals('failed', $job->status);
        $this->assertEquals('Something went wrong', $job->errorMessage);
        $this->assertEquals('stack trace here', $job->errorTrace);
    }

    public function testUpdateProgressSetsProgressAndMessage(): void
    {
        $id = $this->storage->createJob('test.job', []);
        $this->storage->claimJob($id, 'worker-1');

        $updated = $this->storage->updateProgress($id, 50, 'Halfway done');

        $this->assertTrue($updated);

        $job = $this->storage->find($id);
        $this->assertEquals(50, $job->progress);
        $this->assertEquals('Halfway done', $job->progressMessage);
    }

    public function testScheduleRetrySetsStatusToPending(): void
    {
        $id = $this->storage->createJob('test.job', []);
        $this->storage->claimJob($id, 'worker-1');

        $scheduled = $this->storage->scheduleRetry($id, 1, 5, 'Temporary error');

        $this->assertTrue($scheduled);

        $job = $this->storage->find($id);
        $this->assertEquals('pending', $job->status);
        $this->assertEquals(1, $job->attempts);
        $this->assertNotNull($job->availableAt);
        $this->assertEquals('Temporary error', $job->errorMessage);
        $this->assertNull($job->lockedBy);
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
        $this->storage->claimJob($id, 'worker-1');

        $found = $this->storage->findActiveByRequestId('req-def');

        $this->assertNotNull($found);
        $this->assertEquals($id, $found->id);
        $this->assertEquals('running', $found->status);
    }

    public function testFindActiveByRequestIdReturnsNullForCompletedJob(): void
    {
        $id = $this->storage->createJob('test.job', [], 'default', 3, 'req-ghi');
        $this->storage->claimJob($id, 'worker-1');
        $this->storage->markCompleted($id);

        $found = $this->storage->findActiveByRequestId('req-ghi');

        $this->assertNull($found);
    }

    public function testFindActiveByRequestIdReturnsNullForFailedJob(): void
    {
        $id = $this->storage->createJob('test.job', [], 'default', 3, 'req-jkl');
        $this->storage->claimJob($id, 'worker-1');
        $this->storage->markFailed($id, 'Error');

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
        $this->storage->claimJob($id1, 'worker-1');

        $pendingJobs = $this->storage->list('pending');
        $runningJobs = $this->storage->list('running');

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
        $this->storage->claimJob($id1, 'worker-1');

        $this->assertEquals(1, $this->storage->count('pending'));
        $this->assertEquals(1, $this->storage->count('running'));
        $this->assertEquals(0, $this->storage->count('completed'));
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
}
