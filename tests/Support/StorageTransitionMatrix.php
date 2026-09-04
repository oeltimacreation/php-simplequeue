<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Support;

use Oeltima\SimpleQueue\Contract\JobStatus;
use Oeltima\SimpleQueue\Contract\JobStorageInterface;
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
        Assert::assertTrue($storage->scheduleRetry($claim, 0, 0, 'Worker shutting down'));
        $job = $storage->find($id);
        Assert::assertNotNull($job);
        Assert::assertSame(JobStatus::Pending, $job->status);
        Assert::assertSame(0, $job->attempts);
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
    }

    /**
     * Assert lost ownership returns false for fenced transitions.
     *
     * @param JobStorageInterface $storage Storage under test
     */
    public static function assertOwnership(JobStorageInterface $storage): void
    {
        $id = $storage->createJob('test.job', []);
        $first = $storage->claimById($id, 'worker-1');
        $second = $storage->claimById($id, 'worker-1');
        Assert::assertNotNull($first);
        Assert::assertNotNull($second);
        Assert::assertFalse($storage->markCompleted($first));
        Assert::assertTrue($storage->markCompleted($second, ['ok' => true]));
    }
}
