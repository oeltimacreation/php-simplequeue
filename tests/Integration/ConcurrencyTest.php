<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Integration;

use Oeltima\SimpleQueue\Contract\JobStatus;
use Oeltima\SimpleQueue\Contract\JobData;
use Oeltima\SimpleQueue\Storage\InMemoryJobStorage;
use Oeltima\SimpleQueue\Storage\PdoJobStorage;
use Oeltima\SimpleQueue\Tests\Support\FrozenClock;
use Oeltima\SimpleQueue\Tests\Support\SqliteFixture;
use PDO;
use PHPUnit\Framework\TestCase;

final class ConcurrencyTest extends TestCase
{
    private ?string $dbFile = null;

    protected function tearDown(): void
    {
        if ($this->dbFile !== null && file_exists($this->dbFile)) {
            unlink($this->dbFile);
        }
    }

    private function createSqlitePdo(string $dbFile): PDO
    {
        $pdo = SqliteFixture::filePdo($dbFile);
        \Oeltima\SimpleQueue\Tests\DbHelper::createSchema($pdo);
        return $pdo;
    }

    /** @return array<string, \Oeltima\SimpleQueue\Contract\JobStorageInterface> */
    private function contractStorages(FrozenClock $clock): array
    {
        $dbFile = tempnam(sys_get_temp_dir(), 'sq_test_');
        if ($dbFile === false) {
            throw new \RuntimeException('Could not create temporary SQLite database');
        }
        $this->dbFile = $dbFile;
        return [
            'InMemory' => new InMemoryJobStorage($clock),
            'Pdo' => new PdoJobStorage($this->createSqlitePdo($dbFile), 'background_jobs', $clock),
        ];
    }

    /**
     * Test fencing and lost ownership prevention.
     * Ensure that if a job's lease has expired/been taken over, the original worker cannot modify the job.
     */
    public function testFencingAndLostOwnership(): void
    {
        $clock = new FrozenClock();

        foreach ($this->contractStorages($clock) as $name => $storage) {
            $id = $storage->createJob('test.job', []);

            // Worker 1 claims job
            $claim1 = $storage->claimById($id, 'worker-1');
            self::assertNotNull($claim1, "$name: worker-1 should claim");

            // Simulate worker 1 crashing/stale recovery running
            $clock->advance(600);
            $recovered = $storage->recoverStaleJobs(300); // recover jobs locked for more than 300s
            self::assertSame(1, $recovered, "$name: recover should find 1 job");

            // Worker 2 claims the now-pending job
            $claim2 = $storage->claimById($id, 'worker-2');
            self::assertNotNull($claim2, "$name: worker-2 should claim recovered job");
            self::assertNotEquals($claim1->leaseToken, $claim2->leaseToken, "$name: lease tokens must differ");

            // Worker 2 completes job successfully
            self::assertTrue($storage->markCompleted($claim2, ['res' => 2]), "$name: worker-2 should complete");

            // Zombie worker 1 tries to complete job -> should fail due to fencing/lost ownership
            self::assertFalse($storage->markCompleted($claim1, ['res' => 1]), "$name: zombie worker-1 complete must fail");

            // Zombie worker 1 tries to update progress -> should fail
            self::assertFalse($storage->updateProgress($claim1, 50, 'Zombied'), "$name: zombie worker-1 update progress must fail");

            // Zombie worker 1 tries to heartbeat -> should fail
            self::assertFalse($storage->heartbeat($claim1), "$name: zombie worker-1 heartbeat must fail");
        }
    }

    /**
     * Test poison job recovery.
     * Ensure that jobs that repeatedly crash (e.g. maxAttempts exhausted via stale recovery)
     * are eventually marked as failed rather than retried infinitely.
     */
    public function testPoisonJobRecovery(): void
    {
        $clock = new FrozenClock();

        foreach ($this->contractStorages($clock) as $name => $storage) {
            $id = $storage->createJob('poison.job', [], 'default', 3); // Max attempts = 3

            $job = $this->recoverCrashedJob($storage, $clock, $id, 'worker-1');
            self::assertSame(JobStatus::Pending, $job->status, "$name: job should be pending");
            self::assertSame(1, $job->attempts, "$name: attempts should be 1");

            $job = $this->recoverCrashedJob($storage, $clock, $id, 'worker-2');
            self::assertSame(JobStatus::Pending, $job->status, "$name: job should be pending");
            self::assertSame(2, $job->attempts, "$name: attempts should be 2");

            $job = $this->recoverCrashedJob($storage, $clock, $id, 'worker-3');
            self::assertSame(JobStatus::Failed, $job->status, "$name: job should be failed");
            self::assertNotNull($job->errorMessage);
            self::assertStringContainsString('stale recovery', $job->errorMessage, "$name: error message should match");
        }
    }

    private function recoverCrashedJob(
        \Oeltima\SimpleQueue\Contract\JobStorageInterface $storage,
        FrozenClock $clock,
        int $id,
        string $workerId
    ): JobData {
        self::assertNotNull($storage->claimById($id, $workerId), "{$workerId} claim failed");
        $clock->advance(600);
        self::assertSame(1, $storage->recoverStaleJobs(300), "{$workerId} recovery failed");
        $job = $storage->find($id);
        self::assertNotNull($job);
        return $job;
    }

    /**
     * Test SKIP LOCKED claim distribution if running on MySQL or PostgreSQL.
     */
    public function testSkipLockedClaimDistribution(): void
    {
        // Check if there are MySQL or PostgreSQL env variables to test with a real DB.
        // Otherwise, skip this test.
        $dsn = getenv('DB_DSN');
        if (!is_string($dsn) || $dsn === '') {
            self::markTestSkipped('Real DB_DSN environment variable not set. Skipping SKIP LOCKED distribution test.');
        }

        $userValue = getenv('DB_USER');
        $passwordValue = getenv('DB_PASSWORD');
        $user = is_string($userValue) ? $userValue : '';
        $password = is_string($passwordValue) ? $passwordValue : '';

        $pdo1 = new PDO($dsn, $user, $password);
        $pdo2 = new PDO($dsn, $user, $password);

        $pdo1->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo2->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $driver = $pdo1->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            self::markTestSkipped('SKIP LOCKED concurrency test is not supported on SQLite.');
        }

        // Re-create table if needed
        $pdo1->exec('DROP TABLE IF EXISTS test_concurrency_jobs');
        \Oeltima\SimpleQueue\Tests\DbHelper::createSchema($pdo1, 'test_concurrency_jobs');

        $storage1 = new PdoJobStorage($pdo1, 'test_concurrency_jobs');
        $storage2 = new PdoJobStorage($pdo2, 'test_concurrency_jobs');

        // Create two jobs
        $jobId1 = $storage1->createJob('job1', [], 'default');
        $jobId2 = $storage1->createJob('job2', [], 'default');

        // Start a transaction on connection 1 and lock the first job manually
        $pdo1->beginTransaction();
        $stmt = $pdo1->prepare('SELECT * FROM test_concurrency_jobs WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $jobId1]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertNotFalse($row);

        // Connection 2 (separate connection/transaction) should claim the next one, SKIPPING the locked one!
        // We do not call beginTransaction() on pdo2 because claimNextAvailable manages its transaction.
        $claim2 = $storage2->claimNextAvailable('default', 'worker-2');
        self::assertNotNull($claim2);
        self::assertSame($jobId2, $claim2->job->id);

        // Commit transaction to release lock
        $pdo1->commit();

        $pdo1->exec('DROP TABLE IF EXISTS test_concurrency_jobs');
    }
}
