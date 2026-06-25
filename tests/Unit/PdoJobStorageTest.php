<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Unit;

use Oeltima\SimpleQueue\Contract\ClockInterface;
use Oeltima\SimpleQueue\Contract\JobStatus;
use Oeltima\SimpleQueue\Storage\PdoJobStorage;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

class PdoJobStorageTest extends TestCase
{
    private function createSqlitePdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createSchema($pdo);

        return $pdo;
    }

    private function createSchema(PDO $pdo): void
    {
        \Oeltima\SimpleQueue\Tests\DbHelper::createSchema($pdo);
    }

    public function testConstructorAcceptsPdoInstance(): void
    {
        $pdo = $this->createSqlitePdo();
        $storage = new PdoJobStorage($pdo);

        $id = $storage->createJob('test.job', ['data' => 'value']);
        $this->assertEquals(1, $id);

        $job = $storage->find($id);
        $this->assertNotNull($job);
        $this->assertEquals('test.job', $job->type);
    }

    public function testConstructorEnforcesExceptionErrorModeForPdoInstance(): void
    {
        $pdo = $this->createSqlitePdo();
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);

        new PdoJobStorage($pdo);

        $this->assertSame(PDO::ERRMODE_EXCEPTION, $pdo->getAttribute(PDO::ATTR_ERRMODE));
    }

    public function testConstructorAcceptsCallableFactory(): void
    {
        $callCount = 0;
        $factory = function () use (&$callCount): PDO {
            $callCount++;
            return $this->createSqlitePdo();
        };

        $storage = new PdoJobStorage($factory);

        $id = $storage->createJob('test.job', ['data' => 'value']);
        $this->assertEquals(1, $id);
        $this->assertEquals(1, $callCount, 'Factory should be called once for initial connection');
    }

    public function testFactoryConnectionEnforcesExceptionErrorMode(): void
    {
        $createdPdo = null;
        $factory = function () use (&$createdPdo): PDO {
            $createdPdo = $this->createSqlitePdo();
            $createdPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);

            return $createdPdo;
        };

        $storage = new PdoJobStorage($factory);
        $storage->createJob('test.job', []);

        $this->assertInstanceOf(PDO::class, $createdPdo);
        $this->assertSame(PDO::ERRMODE_EXCEPTION, $createdPdo->getAttribute(PDO::ATTR_ERRMODE));
    }

    public function testReconnectForcesNewConnection(): void
    {
        $callCount = 0;
        $factory = function () use (&$callCount): PDO {
            $callCount++;
            return $this->createSqlitePdo();
        };

        $storage = new PdoJobStorage($factory);

        $storage->createJob('test.job', []);
        $this->assertEquals(1, $callCount);

        $storage->reconnect();

        $storage->createJob('test.job', []);
        $this->assertEquals(2, $callCount, 'Factory should be called again after reconnect');
    }

    public function testAutoReconnectsOnStaleConnection(): void
    {
        $callCount = 0;
        $goodPdo = null;

        $factory = function () use (&$callCount, &$goodPdo): PDO {
            $callCount++;
            $goodPdo = $this->createSqlitePdo();
            return $goodPdo;
        };

        $storage = new PdoJobStorage($factory);

        $storage->createJob('test.job', []);
        $this->assertEquals(1, $callCount);

        $storage->createJob('another.job', []);
        $this->assertEquals(1, $callCount, 'Should reuse existing healthy connection');
    }

    public function testDoesNotRunHealthCheckBeforeEveryQuery(): void
    {
        $pdo = new class ('sqlite::memory:') extends PDO {
            public int $queryCount = 0;

            public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
            {
                $this->queryCount++;

                return parent::query($query, $fetchMode, ...$fetchModeArgs);
            }
        };
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createSchema($pdo);

        $storage = new PdoJobStorage($pdo);

        $id = $storage->createJob('test.job', []);
        $storage->find($id);

        $this->assertSame(0, $pdo->queryCount);
    }

    public function testReconnectsAfterConnectionLossException(): void
    {
        $callCount = 0;
        $factory = function () use (&$callCount): PDO {
            $callCount++;

            if ($callCount === 1) {
                return new class ('sqlite::memory:') extends PDO {
                    public function prepare(string $query, array $options = []): PDOStatement|false
                    {
                        throw new \PDOException('SQLSTATE[HY000]: 2006 MySQL server has gone away', 2006);
                    }
                };
            }

            return $this->createSqlitePdo();
        };

        $storage = new PdoJobStorage($factory);

        $id = $storage->createJob('test.job', []);

        $this->assertSame(1, $id);
        $this->assertSame(2, $callCount);
    }

    public function testCreateJobStoresPayload(): void
    {
        $pdo = $this->createSqlitePdo();
        $storage = new PdoJobStorage($pdo);

        $payload = ['user_id' => 123, 'action' => 'import'];
        $id = $storage->createJob('import.users', $payload);

        $job = $storage->find($id);
        $this->assertEquals($payload, $job->payload);
    }

    public function testCreateJobUsesInjectedClock(): void
    {
        $pdo = $this->createSqlitePdo();
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
        $storage = new PdoJobStorage($pdo, 'background_jobs', $clock);

        $id = $storage->createJob('test.job', []);
        $job = $storage->find($id);

        $this->assertEquals('2026-01-02 03:04:05', $job->createdAt);
        $this->assertEquals('2026-01-02 03:04:05', $job->updatedAt);
        $this->assertEquals('2026-01-02 03:04:05', $job->availableAt);
    }

    public function testScheduleRetryUsesInjectedClock(): void
    {
        $pdo = $this->createSqlitePdo();
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
        $storage = new PdoJobStorage($pdo, 'background_jobs', $clock);

        $id = $storage->createJob('test.job', []);
        $claim = $storage->claimById($id, 'worker-1');
        $this->assertNotNull($claim);
        $storage->scheduleRetry($claim, 1, 60, 'Temporary failure');

        $job = $storage->find($id);
        $this->assertEquals('2026-01-02 03:05:05', $job->availableAt);
    }

    public function testClaimByIdLocksProperly(): void
    {
        $pdo = $this->createSqlitePdo();
        $storage = new PdoJobStorage($pdo);

        $id = $storage->createJob('test.job', []);

        $claim = $storage->claimById($id, 'worker-1');
        $this->assertNotNull($claim);

        $job = $storage->find($id);
        $this->assertSame(JobStatus::Running, $job->status);
        $this->assertEquals('worker-1', $job->lockedBy);
        $this->assertNotNull($job->leaseToken);

        $claimAgain = $storage->claimById($id, 'worker-2');
        $this->assertNull($claimAgain, 'Should not claim already running job');
    }

    public function testClaimByIdAllowsSameWorkerToReclaim(): void
    {
        $pdo = $this->createSqlitePdo();
        $storage = new PdoJobStorage($pdo);

        $id = $storage->createJob('test.job', []);

        $claim1 = $storage->claimById($id, 'worker-1');
        $this->assertNotNull($claim1);

        $claim2 = $storage->claimById($id, 'worker-1');
        $this->assertNotNull($claim2);
        $this->assertEquals($id, $claim2->job->id);
        $this->assertNotEquals($claim1->leaseToken, $claim2->leaseToken);
    }

    public function testClaimNextAvailableReturnsClaimedJob(): void
    {
        $pdo = $this->createSqlitePdo();
        $storage = new PdoJobStorage($pdo);

        $id = $storage->createJob('test.job', [], 'default');

        $claim = $storage->claimNextAvailable('default', 'worker-1');

        $this->assertNotNull($claim);
        $this->assertEquals($id, $claim->job->id);
        $this->assertEquals('worker-1', $claim->workerId);
        $this->assertNotEmpty($claim->leaseToken);
        $this->assertEquals($claim->leaseToken, $claim->job->leaseToken);
        $this->assertSame(JobStatus::Running, $claim->job->status);

        $this->assertNull($storage->claimNextAvailable('default', 'worker-2'));
    }

    public function testClaimByIdReturnsNullForUnavailableJob(): void
    {
        $pdo = $this->createSqlitePdo();
        $storage = new PdoJobStorage($pdo);

        $id = $storage->createJob('test.job', []);
        $this->assertNotNull($storage->claimById($id, 'worker-1'));

        $this->assertNull($storage->claimById($id, 'worker-2'));
    }

    public function testClaimNextAvailableUsesAvailableAtOrdering(): void
    {
        $pdo = $this->createSqlitePdo();
        $storage = new PdoJobStorage($pdo);

        $laterId = $storage->createJob('later.job', []);
        $earlierId = $storage->createJob('earlier.job', []);

        $pdo->exec("UPDATE background_jobs SET available_at = '2026-01-02 03:04:06' WHERE id = {$laterId}");
        $pdo->exec("UPDATE background_jobs SET available_at = '2026-01-02 03:04:05' WHERE id = {$earlierId}");

        $claim = $storage->claimNextAvailable('default', 'worker-1');

        $this->assertNotNull($claim);
        $this->assertSame($earlierId, $claim->job->id);
    }

    public function testMarkCompletedWithResult(): void
    {
        $pdo = $this->createSqlitePdo();
        $storage = new PdoJobStorage($pdo);

        $id = $storage->createJob('test.job', []);
        $claim = $storage->claimById($id, 'worker-1');
        $this->assertNotNull($claim);

        $result = ['imported' => 100, 'failed' => 5];
        $storage->markCompleted($claim, $result);

        $job = $storage->find($id);
        $this->assertSame(JobStatus::Completed, $job->status);
        $this->assertEquals($result, $job->result);
    }

    public function testUpdateProgress(): void
    {
        $pdo = $this->createSqlitePdo();
        $storage = new PdoJobStorage($pdo);

        $id = $storage->createJob('test.job', []);
        $claim = $storage->claimById($id, 'worker-1');
        $this->assertNotNull($claim);

        $storage->updateProgress($claim, 50, 'Halfway there');

        $job = $storage->find($id);
        $this->assertEquals(50, $job->progress);
        $this->assertEquals('Halfway there', $job->progressMessage);
    }

    public function testScheduleRetry(): void
    {
        $pdo = $this->createSqlitePdo();
        $storage = new PdoJobStorage($pdo);

        $id = $storage->createJob('test.job', []);
        $claim = $storage->claimById($id, 'worker-1');
        $this->assertNotNull($claim);

        $storage->scheduleRetry($claim, 1, 60, 'Temporary failure');

        $job = $storage->find($id);
        $this->assertSame(JobStatus::Pending, $job->status);
        $this->assertEquals(1, $job->attempts);
        $this->assertNull($job->lockedBy);
        $this->assertNotNull($job->availableAt);
    }

    public function testRecoverStaleJobsIncrementsAttempts(): void
    {
        $pdo = $this->createSqlitePdo();
        $storage = new PdoJobStorage($pdo);

        $id = $storage->createJob('test.job', [], 'default', 3);
        $claim = $storage->claimById($id, 'worker-1');
        $this->assertNotNull($claim);

        $pdo->exec("UPDATE background_jobs SET locked_at = '2026-01-01 00:00:00'");

        $recovered = $storage->recoverStaleJobs(60);
        $this->assertSame(1, $recovered);

        $job = $storage->find($id);
        $this->assertSame(JobStatus::Pending, $job->status);
        $this->assertSame(1, $job->attempts);
        $this->assertNull($job->lockedBy);
        $this->assertNull($job->leaseToken);
    }

    public function testRecoverStaleJobsFailsPoisonJobs(): void
    {
        $pdo = $this->createSqlitePdo();
        $storage = new PdoJobStorage($pdo);

        $id = $storage->createJob('test.job', [], 'default', 1);
        $claim = $storage->claimById($id, 'worker-1');
        $this->assertNotNull($claim);

        $pdo->exec("UPDATE background_jobs SET locked_at = '2026-01-01 00:00:00'");

        $recovered = $storage->recoverStaleJobs(60);
        $this->assertSame(1, $recovered);

        $job = $storage->find($id);
        $this->assertSame(JobStatus::Failed, $job->status);
        $this->assertSame('Job timed out / worker crashed (stale recovery)', $job->errorMessage);
        $this->assertNull($job->lockedBy);
        $this->assertNull($job->leaseToken);
        $this->assertNotNull($job->completedAt);
    }

    public function testCreateJobsBatch(): void
    {
        $pdo = $this->createSqlitePdo();
        $storage = new PdoJobStorage($pdo);

        $jobs = [
            ['type' => 'test.job1', 'payload' => ['a' => 1], 'queue' => 'default', 'maxAttempts' => 3],
            ['type' => 'test.job2', 'payload' => ['b' => 2], 'queue' => 'default', 'maxAttempts' => 5],
        ];

        $ids = $storage->createJobs($jobs);
        $this->assertCount(2, $ids);

        $job1 = $storage->find($ids[0]);
        $this->assertNotNull($job1);
        $this->assertEquals('test.job1', $job1->type);
        $this->assertEquals(['a' => 1], $job1->payload);
        $this->assertEquals(3, $job1->maxAttempts);

        $job2 = $storage->find($ids[1]);
        $this->assertNotNull($job2);
        $this->assertEquals('test.job2', $job2->type);
        $this->assertEquals(['b' => 2], $job2->payload);
        $this->assertEquals(5, $job2->maxAttempts);
    }

    public function testCancelPendingJob(): void
    {
        $pdo = $this->createSqlitePdo();
        $storage = new PdoJobStorage($pdo);

        $id = $storage->createJob('test.job', []);

        $result = $storage->cancel($id);

        $this->assertTrue($result);
        $job = $storage->find($id);
        $this->assertNotNull($job);
        $this->assertSame(JobStatus::Cancelled, $job->status);
    }

    public function testCancelNonPendingJobFails(): void
    {
        $pdo = $this->createSqlitePdo();
        $storage = new PdoJobStorage($pdo);

        $id = $storage->createJob('test.job', []);
        $claim = $storage->claimById($id, 'worker-1');
        $this->assertNotNull($claim);

        $result = $storage->cancel($id);

        $this->assertFalse($result);
        $job = $storage->find($id);
        $this->assertNotNull($job);
        $this->assertSame(JobStatus::Running, $job->status);
    }

    public function testCancelNonExistentJobFails(): void
    {
        $pdo = $this->createSqlitePdo();
        $storage = new PdoJobStorage($pdo);

        $result = $storage->cancel(9999);
        $this->assertFalse($result);
    }
}
