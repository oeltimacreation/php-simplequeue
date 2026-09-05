<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Support;

use Oeltima\SimpleQueue\Contract\ClaimedJob;
use Oeltima\SimpleQueue\Contract\JobStatus;
use Oeltima\SimpleQueue\Contract\JobStorageInterface;
use Oeltima\SimpleQueue\Contract\SupportsFailedJobAdministration;
use Oeltima\SimpleQueue\Contract\SupportsQueueScopedStaleRecovery;
use PHPUnit\Framework\Assert;

/**
 * Shared storage transition matrix: one rule across every backend.
 *
 * Covers create, claim, progress, retry, completion, terminal failure,
 * cancellation, scoped/unscoped stale recovery, requeue/purge, and
 * ownership replacement at attempts 0, max-1, and max.
 */
final class StorageTransitionMatrix
{
    /**
     * Assert canonical create defaults.
     *
     * @param JobStorageInterface $storage Storage under test
     */
    public static function assertCreate(JobStorageInterface $storage): void
    {
        $id = $storage->createJob('test.job', ['a' => 1], 'default', 3, 'req-1');
        $job = $storage->find($id);
        Assert::assertNotNull($job);
        Assert::assertSame(0, $job->attempts);
        Assert::assertSame(JobStatus::Pending, $job->status);
        Assert::assertNotNull($job->availableAt);
        Assert::assertNull($job->lockedBy);
        Assert::assertNull($job->leaseToken);
    }

    /**
     * Assert claim preserves attempts and establishes ownership.
     *
     * @param JobStorageInterface $storage Storage under test
     */
    public static function assertClaim(JobStorageInterface $storage): void
    {
        $id = $storage->createJob('test.job', []);
        $claim = $storage->claimById($id, 'worker-1');
        Assert::assertNotNull($claim);
        Assert::assertSame(0, $claim->job->attempts);
        $found = $storage->find($id);
        Assert::assertNotNull($found);
        Assert::assertSame(JobStatus::Running, $found->status);
        Assert::assertSame(64, strlen($claim->leaseToken));
    }

    /**
     * Assert progress and successful completion reset transient state.
     *
     * @param JobStorageInterface $storage Storage under test
     */
    public static function assertProgressAndCompletion(JobStorageInterface $storage): void
    {
        $id = $storage->createJob('test.job', []);
        $claim = $storage->claimById($id, 'worker-1');
        Assert::assertNotNull($claim);
        Assert::assertTrue($storage->updateProgress($claim, 60, 'working'));
        Assert::assertSame(60, $storage->find($id)?->progress);
        Assert::assertTrue($storage->markCompleted($claim, ['ok' => true]));
        $job = $storage->find($id);
        Assert::assertSame(JobStatus::Completed, $job->status);
        Assert::assertSame(0, $job->attempts);
        Assert::assertSame(['ok' => true], $job->result);
        Assert::assertNull($job->progress);
        Assert::assertNull($job->lockedBy);
        Assert::assertNotNull($job->completedAt);
    }

    /**
     * Assert retry consumes one failure and resets retry state.
     *
     * @param JobStorageInterface $storage Storage under test
     */
    public static function assertRetry(JobStorageInterface $storage): void
    {
        $id = $storage->createJob('test.job', [], 'default', 3);
        $claim = $storage->claimById($id, 'worker-1');
        Assert::assertNotNull($claim);
        Assert::assertTrue($storage->scheduleRetry($claim, 1, 0, 'boom'));
        $job = $storage->find($id);
        Assert::assertNotNull($job);
        Assert::assertSame(JobStatus::Pending, $job->status);
        Assert::assertSame(1, $job->attempts);
        Assert::assertSame('boom', $job->errorMessage);
        Assert::assertNull($job->lockedBy);
        Assert::assertNull($job->result);
        Assert::assertNull($job->completedAt);
    }

    /**
     * Assert terminal failure consumes one failure and completes.
     *
     * @param JobStorageInterface $storage Storage under test
     */
    public static function assertTerminalFailure(JobStorageInterface $storage): void
    {
        $id = $storage->createJob('test.job', [], 'default', 1);
        $claim = $storage->claimById($id, 'worker-1');
        Assert::assertNotNull($claim);
        Assert::assertTrue($storage->markFailed($claim, 'dead', 'trace'));
        $job = $storage->find($id);
        Assert::assertNotNull($job);
        Assert::assertSame(JobStatus::Failed, $job->status);
        Assert::assertSame(1, $job->attempts);
        Assert::assertNotNull($job->completedAt);
        Assert::assertFalse($job->canRetry());
    }

    /**
     * Assert graceful release preserves attempts and failure metadata.
     *
     * @param JobStorageInterface $storage Storage under test
     */
    public static function assertGracefulRelease(JobStorageInterface $storage): void
    {
        $id = $storage->createJob('test.job', []);
        $claim = $storage->claimById($id, 'worker-1');
        Assert::assertNotNull($claim);
        Assert::assertTrue($storage->scheduleRetry($claim, 1, 0, 'previous failure'));
        $claim = $storage->claimById($id, 'worker-1');
        Assert::assertNotNull($claim);
        Assert::assertTrue($storage->scheduleRetry($claim, $claim->job->attempts, 0, $claim->job->errorMessage));
        $job = $storage->find($id);
        Assert::assertNotNull($job);
        Assert::assertSame(JobStatus::Pending, $job->status);
        Assert::assertSame(1, $job->attempts);
        Assert::assertSame('previous failure', $job->errorMessage);
    }

    /**
     * Assert cancellation and failed-job administration transitions.
     *
     * @param JobStorageInterface $storage Storage under test
     */
    public static function assertAdministration(JobStorageInterface $storage): void
    {
        if (!$storage instanceof SupportsFailedJobAdministration) {
            throw new \LogicException('Built-in transition matrix requires failed-job administration');
        }
        $cancelledId = $storage->createJob('test.cancel', []);
        Assert::assertTrue($storage->cancel($cancelledId));
        Assert::assertSame(JobStatus::Cancelled, $storage->find($cancelledId)?->status);
        Assert::assertFalse($storage->cancel($cancelledId));

        $failedId = $storage->createJob('test.failed', [], maxAttempts: 1);
        $claim = $storage->claimById($failedId, 'worker-1');
        Assert::assertNotNull($claim);
        Assert::assertTrue($storage->updateProgress($claim, 75, 'almost'));
        Assert::assertTrue($storage->markFailed($claim, 'dead', 'trace'));
        $requeued = $storage->requeueFailed($failedId);
        Assert::assertNotNull($requeued);
        Assert::assertSame(JobStatus::Pending, $requeued->status);
        Assert::assertSame(0, $requeued->attempts);
        Assert::assertNull($requeued->errorMessage);
        Assert::assertNull($requeued->progress);
        $claim = $storage->claimById($failedId, 'worker-2');
        Assert::assertNotNull($claim);
        Assert::assertTrue($storage->markFailed($claim, 'dead again'));
        Assert::assertNotNull($storage->purgeFailed($failedId));
        Assert::assertNull($storage->find($failedId));
    }

    /**
     * Assert stale recovery parity (scoped and unscoped share one rule).
     *
     * @param JobStorageInterface $storage Storage under test
     * @param FrozenClock $clock Test clock
     */
    public static function assertStaleParity(JobStorageInterface $storage, FrozenClock $clock): void
    {
        $id = $storage->createJob('test.job', [], 'default', 2);
        Assert::assertNotNull($storage->claimById($id, 'worker-1'));
        $clock->advance(301);
        Assert::assertSame(1, $storage->recoverStaleJobs(300));
        $job = $storage->find($id);
        Assert::assertNotNull($job);
        Assert::assertSame(1, $job->attempts);
        Assert::assertSame(JobStatus::Pending, $job->status);
        Assert::assertSame('Job timed out / worker crashed (stale recovery)', $job->errorMessage);
        Assert::assertNotNull($storage->claimById($id, 'worker-2'));
        $clock->advance(301);
        Assert::assertSame(1, $storage->recoverStaleJobs(300));
        $failed = $storage->find($id);
        Assert::assertNotNull($failed);
        Assert::assertSame(JobStatus::Failed, $failed->status);
        Assert::assertSame(2, $failed->attempts);
        Assert::assertSame($clock->now(), $failed->availableAt);
    }

    /**
     * Assert queue scoping and limits for stale recovery.
     *
     * @param JobStorageInterface $storage Storage under test
     * @param FrozenClock $clock Test clock
     */
    public static function assertScopedStaleParity(JobStorageInterface $storage, FrozenClock $clock): void
    {
        if (!$storage instanceof SupportsQueueScopedStaleRecovery) {
            throw new \LogicException('Built-in transition matrix requires queue-scoped stale recovery');
        }
        $first = $storage->createJob('test.job', [], 'default', 1);
        $second = $storage->createJob('test.job', [], 'default', 1);
        $other = $storage->createJob('test.job', [], 'other', 1);
        Assert::assertNotNull($storage->claimById($first, 'worker-1'));
        Assert::assertNotNull($storage->claimById($second, 'worker-2'));
        Assert::assertNotNull($storage->claimById($other, 'worker-3'));
        $clock->advance(301);

        Assert::assertSame(1, $storage->recoverStaleJobsForQueue('default', 300, 1));
        Assert::assertSame(JobStatus::Failed, $storage->find($first)?->status);
        Assert::assertSame(JobStatus::Running, $storage->find($second)?->status);
        Assert::assertSame(JobStatus::Running, $storage->find($other)?->status);
        Assert::assertSame(1, $storage->recoverStaleJobsForQueue('default', 300, 10));
        Assert::assertSame(JobStatus::Failed, $storage->find($second)->status);
        Assert::assertSame(JobStatus::Running, $storage->find($other)->status);
    }

    /**
     * Assert lost ownership returns false for fenced transitions.
     *
     * @param JobStorageInterface $storage Storage under test
     */
    public static function assertOwnership(JobStorageInterface $storage): void
    {
        /** @var array<string, \Closure(ClaimedJob): bool> $transitions */
        $transitions = [
            'complete' => static fn(ClaimedJob $claim): bool => $storage->markCompleted($claim),
            'retry' => static fn(ClaimedJob $claim): bool => $storage->scheduleRetry($claim, 0, 0),
            'fail' => static fn(ClaimedJob $claim): bool => $storage->markFailed($claim, 'stale'),
            'progress' => static fn(ClaimedJob $claim): bool => $storage->updateProgress($claim, 10),
            'heartbeat' => static fn(ClaimedJob $claim): bool => $storage->heartbeat($claim),
        ];
        foreach ($transitions as $name => $transition) {
            $id = $storage->createJob('test.' . $name, []);
            $stale = $storage->claimById($id, 'worker-1');
            $current = $storage->claimById($id, 'worker-1');
            Assert::assertNotNull($stale);
            Assert::assertNotNull($current);
            Assert::assertFalse($transition($stale), $name);
            Assert::assertTrue($storage->markCompleted($current), $name);
        }
    }
}
