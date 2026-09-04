<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Contract;

use Oeltima\SimpleQueue\Contract\JobStatus;
use Oeltima\SimpleQueue\Contract\JobStorageAdminInterface;
use Oeltima\SimpleQueue\Contract\JobStorageInterface;
use Oeltima\SimpleQueue\Storage\InMemoryJobStorage;
use Oeltima\SimpleQueue\Tests\Support\FrozenClock;
use Oeltima\SimpleQueue\Tests\Support\SqliteFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JobStorageContractTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function backends(): iterable
    {
        yield 'in-memory' => ['memory'];
        yield 'SQLite PDO' => ['pdo'];
    }

    #[DataProvider('backends')]
    public function testCreatesHydratesAndFiltersJobs(string $backend): void
    {
        $clock = new FrozenClock();
        $storage = $this->storage($backend, $clock);
        $id = $storage->createJob('mail.send', ['recipient' => 7], 'emails', 4, 'request-7');

        $job = $storage->find($id);
        self::assertNotNull($job);
        self::assertSame('mail.send', $job->type);
        self::assertSame(['recipient' => 7], $job->payload);
        self::assertSame(JobStatus::Pending, $job->status);
        self::assertSame($clock->now(), $job->availableAt);
        self::assertSame($id, $storage->findActiveByRequestId('request-7')?->id);
        self::assertInstanceOf(JobStorageAdminInterface::class, $storage);
        self::assertSame([$id], array_column($storage->list(JobStatus::Pending, 'emails'), 'id'));
        self::assertSame(1, $storage->count(JobStatus::Pending, 'emails'));
    }

    #[DataProvider('backends')]
    public function testClaimsByQueueAndFencesReplacedLeases(string $backend): void
    {
        $storage = $this->storage($backend, new FrozenClock());
        $storage->createJob('mail.send', [], 'other');
        $id = $storage->createJob('mail.send', [], 'emails');

        $first = $storage->claimNextAvailable('emails', 'worker-1');
        self::assertNotNull($first);
        self::assertSame($id, $first->job->id);
        self::assertSame(JobStatus::Running, $first->job->status);
        $replacement = $storage->claimById($id, 'worker-1');
        self::assertNotNull($replacement);
        self::assertNotSame($first->leaseToken, $replacement->leaseToken);
        self::assertFalse($storage->heartbeat($first));
        self::assertFalse($storage->updateProgress($first, 25, 'stale'));
        self::assertFalse($storage->markCompleted($first));
        self::assertTrue($storage->markCompleted($replacement, ['sent' => true]));
        self::assertSame(['sent' => true], $storage->find($id)?->result);
    }

    #[DataProvider('backends')]
    public function testSchedulesRetryWithMatchingAttemptsTimestampAndOwnership(string $backend): void
    {
        $clock = new FrozenClock();
        $storage = $this->storage($backend, $clock);
        $id = $storage->createJob('mail.send', []);
        $claim = $storage->claimById($id, 'worker-1');
        self::assertNotNull($claim);

        self::assertTrue($storage->scheduleRetry($claim, 1, 60, 'temporary'));
        $job = $storage->find($id);
        self::assertNotNull($job);
        self::assertSame(JobStatus::Pending, $job->status);
        self::assertSame(1, $job->attempts);
        self::assertSame('2023-11-14 22:14:20', $job->availableAt);
        self::assertSame('temporary', $job->errorMessage);
        self::assertNull($job->lockedBy);
        self::assertNull($job->lockedAt);
        self::assertNull($job->leaseToken);
        self::assertNull($storage->claimById($id, 'worker-2'));
        $clock->advance(60);
        self::assertNotNull($storage->claimById($id, 'worker-2'));
    }

    #[DataProvider('backends')]
    public function testStaleRecoveryMatchesAttemptsAndTerminalState(string $backend): void
    {
        $clock = new FrozenClock();
        $storage = $this->storage($backend, $clock);
        $id = $storage->createJob('mail.send', [], 'default', 2);
        self::assertNotNull($storage->claimById($id, 'worker-1'));

        $clock->advance(301);
        self::assertSame(1, $storage->recoverStaleJobs(300));
        self::assertSame(1, $storage->find($id)?->attempts);
        self::assertNotNull($storage->claimById($id, 'worker-2'));
        $clock->advance(301);
        self::assertSame(1, $storage->recoverStaleJobs(300));

        $job = $storage->find($id);
        self::assertSame(JobStatus::Failed, $job->status);
        self::assertSame(2, $job->attempts);
        self::assertSame('Job timed out / worker crashed (stale recovery)', $job->errorMessage);
        self::assertNotNull($job->completedAt);
        self::assertNull($job->leaseToken);
    }

    #[DataProvider('backends')]
    public function testValidationMessagesMatch(string $backend): void
    {
        $storage = $this->storage($backend, new FrozenClock());
        $id = $storage->createJob('mail.send', []);
        $claim = $storage->claimById($id, 'worker-1');
        self::assertNotNull($claim);

        try {
            $storage->updateProgress($claim, 101);
            self::fail('Invalid progress must fail');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Progress must be null or an integer between 0 and 100', $exception->getMessage());
        }

        try {
            $storage->scheduleRetry($claim, 0, -1);
            self::fail('Invalid retry arguments must fail');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame(
                'Attempts must not be negative and retry delay must not be negative',
                $exception->getMessage()
            );
        }
    }

    #[DataProvider('backends')]
    public function testCreateJobsHonorsAvailableAtKey(string $backend): void
    {
        $clock = new FrozenClock();
        $storage = $this->storage($backend, $clock);
        $timestamp = $clock->timestamp() + 120;

        $ids = $storage->createJobs([
            ['type' => 'mail.send', 'payload' => [], 'queue' => 'emails', 'availableAt' => $timestamp],
            ['type' => 'mail.send', 'payload' => [], 'queue' => 'emails', 'availableAt' => new \DateTimeImmutable('@' . ($timestamp + 60))],
        ]);
        $ids[] = $storage->createJobs([['type' => 'mail.send', 'payload' => []]])[0];

        $scheduled = $storage->find($ids[0]);
        $scheduledLater = $storage->find($ids[1]);
        $immediate = $storage->find($ids[2]);
        self::assertNotNull($scheduled);
        self::assertNotNull($scheduledLater);
        self::assertNotNull($immediate);
        self::assertSame('2023-11-14 22:15:20', $scheduled->availableAt);
        self::assertSame('2023-11-14 22:16:20', $scheduledLater->availableAt);
        self::assertSame($clock->now(), $immediate->availableAt);
    }

    #[DataProvider('backends')]
    public function testCreateJobsRejectsInvalidAvailableAtValues(string $backend): void
    {
        $storage = $this->storage($backend, new FrozenClock());

        try {
            $storage->createJobs([['type' => 'mail.send', 'payload' => [], 'availableAt' => 0]]);
            self::fail('Non-positive available-at must be rejected');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Available-at timestamp must be a positive Unix timestamp', $exception->getMessage());
        }

        try {
            $storage->createJobs([['type' => 'mail.send', 'payload' => [], 'availableAt' => 'tomorrow']]);
            self::fail('Invalid available-at type must be rejected');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame(
                'Available-at must be an integer Unix timestamp or a DateTimeInterface',
                $exception->getMessage()
            );
        }
    }

    private function storage(string $backend, FrozenClock $clock): JobStorageInterface
    {
        if ($backend === 'memory') {
            return new InMemoryJobStorage($clock);
        }

        return SqliteFixture::createStorage(clock: $clock);
    }
}
