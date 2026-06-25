<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Integration;

use Oeltima\SimpleQueue\Contract\ClockInterface;
use Oeltima\SimpleQueue\Contract\JobStatus;
use Oeltima\SimpleQueue\Storage\InMemoryJobStorage;
use Oeltima\SimpleQueue\Storage\PdoJobStorage;
use Oeltima\SimpleQueue\SystemClock;
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
        $pdo = new PDO("sqlite:$dbFile");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        \Oeltima\SimpleQueue\Tests\DbHelper::createSchema($pdo);
        return $pdo;
    }

    /**
     * Test fencing and lost ownership prevention.
     * Ensure that if a job's lease has expired/been taken over, the original worker cannot modify the job.
     */
    public function testFencingAndLostOwnership(): void
    {
        $clock = new class implements ClockInterface {
            public int $time = 1700000000;

            public function now(): string
            {
                return gmdate('Y-m-d H:i:s', $this->time);
            }

            public function timestamp(): int
            {
                return $this->time;
            }

            public function monotonic(): float
            {
                return (float) $this->time;
            }
        };

        $this->dbFile = tempnam(sys_get_temp_dir(), 'sq_test_');
        $pdo = $this->createSqlitePdo($this->dbFile);
        
        $storages = [
            'InMemory' => new InMemoryJobStorage($clock),
            'Pdo' => new PdoJobStorage($pdo, 'background_jobs', $clock)
        ];

        foreach ($storages as $name => $storage) {
            $id = $storage->createJob('test.job', []);

            // Worker 1 claims job
            $claim1 = $storage->claimById($id, 'worker-1');
            $this->assertNotNull($claim1, "$name: worker-1 should claim");

            // Simulate worker 1 crashing/stale recovery running
            $clock->time += 600;
            $recovered = $storage->recoverStaleJobs(300); // recover jobs locked for more than 300s
            $this->assertSame(1, $recovered, "$name: recover should find 1 job");

            // Worker 2 claims the now-pending job
            $claim2 = $storage->claimById($id, 'worker-2');
            $this->assertNotNull($claim2, "$name: worker-2 should claim recovered job");
            $this->assertNotEquals($claim1->leaseToken, $claim2->leaseToken, "$name: lease tokens must differ");

            // Worker 2 completes job successfully
            $this->assertTrue($storage->markCompleted($claim2, ['res' => 2]), "$name: worker-2 should complete");

            // Zombie worker 1 tries to complete job -> should fail due to fencing/lost ownership
            $this->assertFalse($storage->markCompleted($claim1, ['res' => 1]), "$name: zombie worker-1 complete must fail");

            // Zombie worker 1 tries to update progress -> should fail
            $this->assertFalse($storage->updateProgress($claim1, 50, 'Zombied'), "$name: zombie worker-1 update progress must fail");

            // Zombie worker 1 tries to heartbeat -> should fail
            $this->assertFalse($storage->heartbeat($claim1), "$name: zombie worker-1 heartbeat must fail");
        }
    }

    /**
     * Test poison job recovery.
     * Ensure that jobs that repeatedly crash (e.g. maxAttempts exhausted via stale recovery)
     * are eventually marked as failed rather than retried infinitely.
     */
    public function testPoisonJobRecovery(): void
    {
        // Custom clock to control time and mock stale recovery thresholds
        $clock = new class implements ClockInterface {
            public int $time = 1700000000;

            public function now(): string
            {
                return gmdate('Y-m-d H:i:s', $this->time);
            }

            public function timestamp(): int
            {
                return $this->time;
            }

            public function monotonic(): float
            {
                return (float) $this->time;
            }
        };

        $this->dbFile = tempnam(sys_get_temp_dir(), 'sq_test_');
        $pdo = $this->createSqlitePdo($this->dbFile);

        $storages = [
            'InMemory' => new InMemoryJobStorage($clock),
            'Pdo' => new PdoJobStorage($pdo, 'background_jobs', $clock)
        ];

        foreach ($storages as $name => $storage) {
            $id = $storage->createJob('poison.job', [], 'default', 3); // Max attempts = 3

            // Attempt 1: claim & crash (stale recovery)
            $claim1 = $storage->claimById($id, 'worker-1');
            $this->assertNotNull($claim1, "$name: claim 1 failed");
            
            $clock->time += 600; // Move forward past TTL
            $recovered = $storage->recoverStaleJobs(300);
            $this->assertSame(1, $recovered, "$name: recover 1 failed");

            $job = $storage->find($id);
            $this->assertSame(JobStatus::Pending, $job->status, "$name: job should be pending");
            $this->assertSame(1, $job->attempts, "$name: attempts should be 1");

            // Attempt 2: claim & crash again
            $claim2 = $storage->claimById($id, 'worker-2');
            $this->assertNotNull($claim2, "$name: claim 2 failed");

            $clock->time += 600;
            $recovered = $storage->recoverStaleJobs(300);
            $this->assertSame(1, $recovered, "$name: recover 2 failed");

            $job = $storage->find($id);
            $this->assertSame(JobStatus::Pending, $job->status, "$name: job should be pending");
            $this->assertSame(2, $job->attempts, "$name: attempts should be 2");

            // Attempt 3: claim & crash again. Attempts (3) matches max_attempts (3), should fail
            $claim3 = $storage->claimById($id, 'worker-3');
            $this->assertNotNull($claim3, "$name: claim 3 failed");

            $clock->time += 600;
            $recovered = $storage->recoverStaleJobs(300);
            $this->assertSame(1, $recovered, "$name: recover 3 failed");

            $job = $storage->find($id);
            $this->assertSame(JobStatus::Failed, $job->status, "$name: job should be failed");
            $this->assertStringContainsString('stale recovery', $job->errorMessage, "$name: error message should match");
        }
    }

    /**
     * Test SKIP LOCKED claim distribution if running on MySQL or PostgreSQL.
     */
    public function testSkipLockedClaimDistribution(): void
    {
        // Check if there are MySQL or PostgreSQL env variables to test with a real DB.
        // Otherwise, skip this test.
        $dsn = getenv('DB_DSN');
        if (!$dsn) {
            $this->markTestSkipped('Real DB_DSN environment variable not set. Skipping SKIP LOCKED distribution test.');
        }

        $user = getenv('DB_USER') ?: '';
        $password = getenv('DB_PASSWORD') ?: '';

        $pdo1 = new PDO($dsn, $user, $password);
        $pdo2 = new PDO($dsn, $user, $password);

        $pdo1->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo2->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $driver = $pdo1->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $this->markTestSkipped('SKIP LOCKED concurrency test is not supported on SQLite.');
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
        $this->assertNotFalse($row);

        // Connection 2 (separate connection/transaction) should claim the next one, SKIPPING the locked one!
        // We do not call beginTransaction() on pdo2 because claimNextAvailable manages its transaction.
        $claim2 = $storage2->claimNextAvailable('default', 'worker-2');
        $this->assertNotNull($claim2);
        $this->assertSame($jobId2, $claim2->job->id);

        // Commit transaction to release lock
        $pdo1->commit();

        $pdo1->exec('DROP TABLE IF EXISTS test_concurrency_jobs');
    }
}
