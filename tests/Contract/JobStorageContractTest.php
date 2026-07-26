<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Contract;

use Oeltima\SimpleQueue\Contract\JobStatus;
use Oeltima\SimpleQueue\Contract\JobStorageAdminInterface;
use Oeltima\SimpleQueue\Contract\JobStorageInterface;
use Oeltima\SimpleQueue\Storage\InMemoryJobStorage;
use Oeltima\SimpleQueue\Storage\PdoJobStorage;
use Oeltima\SimpleQueue\Tests\DbHelper;
use Oeltima\SimpleQueue\Tests\Support\FrozenClock;
use PDO;
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
        self::assertSame(1, $job->attempts);
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
                'Attempts must be positive and retry delay must not be negative',
                $exception->getMessage()
            );
        }
    }

    private function storage(string $backend, FrozenClock $clock): JobStorageInterface
    {
        if ($backend === 'memory') {
            return new InMemoryJobStorage($clock);
        }

        $pdo = new PDO('sqlite::memory:');
        DbHelper::createSchema($pdo);
        return new PdoJobStorage($pdo, 'background_jobs', $clock);
    }
}
