<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Unit;

use Oeltima\SimpleQueue\Contract\JobStatus;
use Oeltima\SimpleQueue\Storage\PdoJobStorage;
use Oeltima\SimpleQueue\Tests\Support\FrozenClock;
use Oeltima\SimpleQueue\Tests\Support\SqliteFixture;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

class PdoJobStorageTest extends TestCase
{
    private function createSqlitePdo(): PDO
    {
        $pdo = SqliteFixture::memoryPdo();
        \Oeltima\SimpleQueue\Tests\DbHelper::createSchema($pdo);

        return $pdo;
    }

    private function createConnectionLossPdo(): PDO
    {
        return new class ('sqlite::memory:') extends PDO {
            /** @param array<array-key, mixed> $options */
            public function prepare(string $query, array $options = []): PDOStatement|false
            {
                throw new \PDOException('SQLSTATE[HY000]: 2006 MySQL server has gone away', 2006);
            }
        };
    }

    public function testConstructorAcceptsPdoInstance(): void
    {
        $pdo = $this->createSqlitePdo();
        $storage = new PdoJobStorage($pdo);

        $id = $storage->createJob('test.job', ['data' => 'value']);
        self::assertEquals(1, $id);

        $job = $storage->find($id);
        self::assertNotNull($job);
        self::assertEquals('test.job', $job->type);
    }

    public function testConstructorEnforcesExceptionErrorModeForPdoInstance(): void
    {
        $pdo = $this->createSqlitePdo();
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);

        new PdoJobStorage($pdo);

        self::assertSame(PDO::ERRMODE_EXCEPTION, $pdo->getAttribute(PDO::ATTR_ERRMODE));
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
        self::assertEquals(1, $id);
        self::assertEquals(1, $callCount, 'Factory should be called once for initial connection');
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

        self::assertInstanceOf(PDO::class, $createdPdo);
        self::assertSame(PDO::ERRMODE_EXCEPTION, $createdPdo->getAttribute(PDO::ATTR_ERRMODE));
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
        self::assertEquals(1, $callCount);

        $storage->reconnect();

        $storage->createJob('test.job', []);
        self::assertEquals(2, $callCount, 'Factory should be called again after reconnect');
    }

    public function testReconnectRejectsDirectPdoStorageWithoutDiscardingConnection(): void
    {
        $storage = new PdoJobStorage($this->createSqlitePdo());

        try {
            $storage->reconnect();
            self::fail('Direct PDO storage must reject reconnect');
        } catch (\LogicException $exception) {
            self::assertSame('Direct-PDO storage has no connection factory to reconnect with', $exception->getMessage());
        }

        self::assertSame(1, $storage->createJob('test.job', []));
    }

    public function testAutoReconnectsOnStaleConnection(): void
    {
        $callCount = 0;
        $factory = function () use (&$callCount): PDO {
            $callCount++;
            return $this->createSqlitePdo();
        };

        $storage = new PdoJobStorage($factory);

        $storage->createJob('test.job', []);
        self::assertEquals(1, $callCount);

        $storage->createJob('another.job', []);
        self::assertEquals(1, $callCount, 'Should reuse existing healthy connection');
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
        \Oeltima\SimpleQueue\Tests\DbHelper::createSchema($pdo);

        $storage = new PdoJobStorage($pdo);

        $id = $storage->createJob('test.job', []);
        $storage->find($id);

        self::assertSame(0, $pdo->queryCount);
    }

    public function testReconnectsAfterConnectionLossException(): void
    {
        $callCount = 0;
        $factory = function () use (&$callCount): PDO {
            $callCount++;

            if ($callCount === 1) {
                return $this->createConnectionLossPdo();
            }

            return $this->createSqlitePdo();
        };

        $storage = new PdoJobStorage($factory);

        // Mutations never replay after a statement attempt; uncertain outcomes raise.
        $this->expectException(\Oeltima\SimpleQueue\Exception\IndeterminateStorageOutcomeException::class);
        $storage->createJob('test.job', []);
    }

    public function testCreateJobStoresPayload(): void
    {
        $pdo = $this->createSqlitePdo();
        $storage = new PdoJobStorage($pdo);

        $payload = ['user_id' => 123, 'action' => 'import'];
        $id = $storage->createJob('import.users', $payload);

        $job = $storage->find($id);
        self::assertNotNull($job);
        self::assertEquals($payload, $job->payload);
    }

    public function testCreateJobUsesInjectedClock(): void
    {
        $pdo = $this->createSqlitePdo();
        $clock = new FrozenClock(1_767_323_045);
        $storage = new PdoJobStorage($pdo, 'background_jobs', $clock);

        $id = $storage->createJob('test.job', []);
        $job = $storage->find($id);

        self::assertNotNull($job);
        self::assertEquals('2026-01-02 03:04:05', $job->createdAt);
        self::assertEquals('2026-01-02 03:04:05', $job->updatedAt);
        self::assertEquals('2026-01-02 03:04:05', $job->availableAt);
    }

    public function testScheduleRetryUsesInjectedClock(): void
    {
        $pdo = $this->createSqlitePdo();
        $clock = new FrozenClock(1_767_323_045);
        $storage = new PdoJobStorage($pdo, 'background_jobs', $clock);

        $id = $storage->createJob('test.job', []);
        $claim = $storage->claimById($id, 'worker-1');
        self::assertNotNull($claim);
        $storage->scheduleRetry($claim, 1, 60, 'Temporary failure');

        $job = $storage->find($id);
        self::assertNotNull($job);
        self::assertEquals('2026-01-02 03:05:05', $job->availableAt);
    }

    public function testClaimByIdLocksProperly(): void
    {
        $pdo = $this->createSqlitePdo();
        $storage = new PdoJobStorage($pdo);

        $id = $storage->createJob('test.job', []);

        $claim = $storage->claimById($id, 'worker-1');
        self::assertNotNull($claim);

        $job = $storage->find($id);
        self::assertNotNull($job);
        self::assertSame(JobStatus::Running, $job->status);
        self::assertEquals('worker-1', $job->lockedBy);
        self::assertNotNull($job->leaseToken);

        $claimAgain = $storage->claimById($id, 'worker-2');
        self::assertNull($claimAgain, 'Should not claim already running job');
    }

    public function testClaimByIdAllowsSameWorkerToReclaim(): void
    {
        $pdo = $this->createSqlitePdo();
        $storage = new PdoJobStorage($pdo);

        $id = $storage->createJob('test.job', []);

        $claim1 = $storage->claimById($id, 'worker-1');
        self::assertNotNull($claim1);

        $claim2 = $storage->claimById($id, 'worker-1');
        self::assertNotNull($claim2);
        self::assertEquals($id, $claim2->job->id);
        self::assertNotEquals($claim1->leaseToken, $claim2->leaseToken);
    }

    public function testReclaimFencesThePreviousLease(): void
    {
        $pdo = $this->createSqlitePdo();
        $storage = new PdoJobStorage($pdo);
        $id = $storage->createJob('test.job', []);

        $firstClaim = $storage->claimById($id, 'worker-1');
        self::assertNotNull($firstClaim);
        $secondClaim = $storage->claimById($id, 'worker-1');
        self::assertNotNull($secondClaim);

        self::assertFalse($storage->markCompleted($firstClaim));
        self::assertTrue($storage->markCompleted($secondClaim));
        self::assertSame(JobStatus::Completed, $storage->find($id)?->status);
    }

    public function testClaimTransactionRollsBackWhenClaimUpdateFails(): void
    {
        $pdo = $this->createSqlitePdo();
        $storage = new PdoJobStorage($pdo);
        $id = $storage->createJob('test.job', []);
        $pdo->exec(
            "CREATE TRIGGER reject_claim BEFORE UPDATE OF status ON background_jobs " .
            "WHEN NEW.status = 'running' BEGIN SELECT RAISE(ABORT, 'claim rejected'); END"
        );

        try {
            $storage->claimById($id, 'worker-1');
            self::fail('Expected the failed claim update to be reported');
        } catch (\PDOException $exception) {
            self::assertStringContainsString('claim rejected', $exception->getMessage());
        }

        self::assertFalse($pdo->inTransaction());
        $job = $storage->find($id);
        self::assertNotNull($job);
        self::assertSame(JobStatus::Pending, $job->status);
        self::assertNull($job->leaseToken);
    }

    public function testClaimNextAvailableReturnsClaimedJob(): void
    {
        $pdo = $this->createSqlitePdo();
        $storage = new PdoJobStorage($pdo);

        $id = $storage->createJob('test.job', [], 'default');

        $claim = $storage->claimNextAvailable('default', 'worker-1');

        self::assertNotNull($claim);
        self::assertEquals($id, $claim->job->id);
        self::assertEquals('worker-1', $claim->workerId);
        self::assertNotEmpty($claim->leaseToken);
        self::assertEquals($claim->leaseToken, $claim->job->leaseToken);
        self::assertSame(JobStatus::Running, $claim->job->status);

        self::assertNull($storage->claimNextAvailable('default', 'worker-2'));
    }

    public function testClaimByIdReturnsNullForUnavailableJob(): void
    {
        $pdo = $this->createSqlitePdo();
        $storage = new PdoJobStorage($pdo);

        $id = $storage->createJob('test.job', []);
        self::assertNotNull($storage->claimById($id, 'worker-1'));

        self::assertNull($storage->claimById($id, 'worker-2'));
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

        self::assertNotNull($claim);
        self::assertSame($earlierId, $claim->job->id);
    }

    public function testMarkCompletedWithResult(): void
    {
        $pdo = $this->createSqlitePdo();
        $storage = new PdoJobStorage($pdo);

        $id = $storage->createJob('test.job', []);
        $claim = $storage->claimById($id, 'worker-1');
        self::assertNotNull($claim);

        $result = ['imported' => 100, 'failed' => 5];
        $storage->markCompleted($claim, $result);

        $job = $storage->find($id);
        self::assertNotNull($job);
        self::assertSame(JobStatus::Completed, $job->status);
        self::assertEquals($result, $job->result);
    }

    public function testUpdateProgress(): void
    {
        $pdo = $this->createSqlitePdo();
        $storage = new PdoJobStorage($pdo);

        $id = $storage->createJob('test.job', []);
        $claim = $storage->claimById($id, 'worker-1');
        self::assertNotNull($claim);

        $storage->updateProgress($claim, 50, 'Halfway there');

        $job = $storage->find($id);
        self::assertNotNull($job);
        self::assertEquals(50, $job->progress);
        self::assertEquals('Halfway there', $job->progressMessage);
    }

    public function testScheduleRetry(): void
    {
        $pdo = $this->createSqlitePdo();
        $storage = new PdoJobStorage($pdo);

        $id = $storage->createJob('test.job', []);
        $claim = $storage->claimById($id, 'worker-1');
        self::assertNotNull($claim);

        $storage->scheduleRetry($claim, 1, 60, 'Temporary failure');

        $job = $storage->find($id);
        self::assertNotNull($job);
        self::assertSame(JobStatus::Pending, $job->status);
        self::assertEquals(1, $job->attempts);
        self::assertNull($job->lockedBy);
        self::assertNotNull($job->availableAt);
    }

    public function testRecoverStaleJobsIncrementsAttempts(): void
    {
        $pdo = $this->createSqlitePdo();
        $storage = new PdoJobStorage($pdo);

        $id = $storage->createJob('test.job', [], 'default', 3);
        $claim = $storage->claimById($id, 'worker-1');
        self::assertNotNull($claim);

        $pdo->exec("UPDATE background_jobs SET locked_at = '2026-01-01 00:00:00'");

        $recovered = $storage->recoverStaleJobs(60);
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
        $pdo = $this->createSqlitePdo();
        $storage = new PdoJobStorage($pdo);

        $id = $storage->createJob('test.job', [], 'default', 1);
        $claim = $storage->claimById($id, 'worker-1');
        self::assertNotNull($claim);

        $pdo->exec("UPDATE background_jobs SET locked_at = '2026-01-01 00:00:00'");

        $recovered = $storage->recoverStaleJobs(60);
        self::assertSame(1, $recovered);

        $job = $storage->find($id);
        self::assertNotNull($job);
        self::assertSame(JobStatus::Failed, $job->status);
        self::assertSame('Job timed out / worker crashed (stale recovery)', $job->errorMessage);
        self::assertNull($job->lockedBy);
        self::assertNull($job->leaseToken);
        self::assertNotNull($job->completedAt);
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
        self::assertCount(2, $ids);

        $job1 = $storage->find($ids[0]);
        self::assertNotNull($job1);
        self::assertEquals('test.job1', $job1->type);
        self::assertEquals(['a' => 1], $job1->payload);
        self::assertEquals(3, $job1->maxAttempts);

        $job2 = $storage->find($ids[1]);
        self::assertNotNull($job2);
        self::assertEquals('test.job2', $job2->type);
        self::assertEquals(['b' => 2], $job2->payload);
        self::assertEquals(5, $job2->maxAttempts);
    }

    public function testCreateJobsUsesSavepointInsideCallerTransaction(): void
    {
        $pdo = $this->createSqlitePdo();
        $storage = new PdoJobStorage($pdo);
        $pdo->beginTransaction();

        try {
            $ids = $storage->createJobs([
                ['type' => 'first.job', 'payload' => []],
                ['type' => 'second.job', 'payload' => []],
            ]);

            self::assertCount(2, $ids);
            self::assertTrue($pdo->inTransaction());
            self::assertSame(2, $storage->count());
        } finally {
            $pdo->rollBack();
        }

        self::assertSame(0, $storage->count());
    }

    public function testCreateJobsValidatesDerivedIdsOnNonReturningDriverPath(): void
    {
        $pdo = new class ('sqlite::memory:') extends PDO {
            public bool $reportMysqlDriver = false;

            public function getAttribute(int $attribute): mixed
            {
                if ($attribute === PDO::ATTR_DRIVER_NAME && $this->reportMysqlDriver) {
                    return 'mysql';
                }
                return parent::getAttribute($attribute);
            }

            public function lastInsertId(?string $name = null): string|false
            {
                $lastId = parent::lastInsertId($name);
                if (!$this->reportMysqlDriver || $lastId === false) {
                    return $lastId;
                }
                // SQLite reports the final multi-row ID; MySQL reports the first.
                return (string) ((int) $lastId - 1);
            }
        };
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        \Oeltima\SimpleQueue\Tests\DbHelper::createSchema($pdo);
        $pdo->reportMysqlDriver = true;
        $storage = new PdoJobStorage($pdo);

        $ids = $storage->createJobs([
            ['type' => 'first.job', 'payload' => []],
            ['type' => 'second.job', 'payload' => []],
        ]);

        self::assertSame([1, 2], $ids);
        self::assertSame(2, $storage->count());
    }

    public function testIdempotentCreationReusesOnlyActiveRequestId(): void
    {
        $storage = new PdoJobStorage($this->createSqlitePdo());

        $first = $storage->createIdempotentJob('test.job', ['version' => 1], 'request-1', 'default', 3);
        $duplicate = $storage->createIdempotentJob('test.job', ['version' => 2], 'request-1', 'default', 3);

        self::assertTrue($first->created);
        self::assertFalse($duplicate->created);
        self::assertSame($first->jobId, $duplicate->jobId);

        $claim = $storage->claimById($first->jobId, 'worker-1');
        self::assertNotNull($claim);
        self::assertTrue($storage->markCompleted($claim));

        $replacement = $storage->createIdempotentJob('test.job', ['version' => 3], 'request-1', 'default', 3);
        self::assertTrue($replacement->created);
        self::assertNotSame($first->jobId, $replacement->jobId);
    }

    public function testIdempotentCreationResolvesUniqueConflictInsideCallerTransaction(): void
    {
        $pdo = $this->createSqlitePdo();
        $storage = new class ($pdo) extends PdoJobStorage {
            public bool $hideNextRequestLookup = false;

            public function findActiveByRequestId(string $requestId): ?\Oeltima\SimpleQueue\Contract\JobData
            {
                if ($this->hideNextRequestLookup) {
                    $this->hideNextRequestLookup = false;
                    return null;
                }
                return parent::findActiveByRequestId($requestId);
            }
        };
        $existingId = $storage->createJob('test.job', [], 'default', 3, 'request-race');
        $pdo->beginTransaction();
        $storage->hideNextRequestLookup = true;

        try {
            $result = $storage->createIdempotentJob('test.job', [], 'request-race', 'default', 3);

            self::assertFalse($result->created);
            self::assertSame($existingId, $result->jobId);
            self::assertTrue($pdo->inTransaction());
        } finally {
            $pdo->rollBack();
        }
    }

    public function testQueueScopedRecoveryHonorsQueueLimitAndRetryBudget(): void
    {
        $pdo = $this->createSqlitePdo();
        $clock = new FrozenClock();
        $storage = new PdoJobStorage($pdo, 'background_jobs', $clock);
        $retryId = $storage->createJob('retry.job', [], 'alpha', 3);
        $terminalId = $storage->createJob('terminal.job', [], 'alpha', 1);
        $otherQueueId = $storage->createJob('other.job', [], 'beta', 3);
        self::assertNotNull($storage->claimById($retryId, 'worker-1'));
        self::assertNotNull($storage->claimById($terminalId, 'worker-2'));
        self::assertNotNull($storage->claimById($otherQueueId, 'worker-3'));
        $clock->advance(61);

        self::assertSame(2, $storage->recoverStaleJobsForQueue('alpha', 60, 2));
        self::assertSame(JobStatus::Pending, $storage->find($retryId)?->status);
        self::assertSame(JobStatus::Failed, $storage->find($terminalId)?->status);
        self::assertSame(JobStatus::Running, $storage->find($otherQueueId)?->status);
        self::assertSame(0, $storage->recoverStaleJobsForQueue('alpha', 60, 2));
    }

    public function testPruneCompletedDeletesOnlyExpiredTerminalRows(): void
    {
        $clock = new FrozenClock();
        $storage = new PdoJobStorage($this->createSqlitePdo(), 'background_jobs', $clock);
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

    public function testCancelPendingJob(): void
    {
        $pdo = $this->createSqlitePdo();
        $storage = new PdoJobStorage($pdo);

        $id = $storage->createJob('test.job', []);

        $result = $storage->cancel($id);

        self::assertTrue($result);
        $job = $storage->find($id);
        self::assertNotNull($job);
        self::assertSame(JobStatus::Cancelled, $job->status);
        self::assertNotNull($job->completedAt);
        self::assertNull($job->lockedBy);
        self::assertNull($job->leaseToken);
    }

    public function testCancelNonPendingJobFails(): void
    {
        $pdo = $this->createSqlitePdo();
        $storage = new PdoJobStorage($pdo);

        $id = $storage->createJob('test.job', []);
        $claim = $storage->claimById($id, 'worker-1');
        self::assertNotNull($claim);

        $result = $storage->cancel($id);

        self::assertFalse($result);
        $job = $storage->find($id);
        self::assertNotNull($job);
        self::assertSame(JobStatus::Running, $job->status);
    }

    public function testCancelNonExistentJobFails(): void
    {
        $pdo = $this->createSqlitePdo();
        $storage = new PdoJobStorage($pdo);

        $result = $storage->cancel(9999);
        self::assertFalse($result);
    }
}
