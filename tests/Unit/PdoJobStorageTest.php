<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Unit;

use Oeltima\SimpleQueue\Storage\PdoJobStorage;
use PDO;
use PHPUnit\Framework\TestCase;

class PdoJobStorageTest extends TestCase
{
    private function createSqlitePdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('
            CREATE TABLE background_jobs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                queue TEXT NOT NULL,
                type TEXT NOT NULL,
                status TEXT NOT NULL,
                payload TEXT,
                result TEXT,
                attempts INTEGER DEFAULT 0,
                max_attempts INTEGER DEFAULT 3,
                progress INTEGER,
                progress_message TEXT,
                available_at TEXT,
                started_at TEXT,
                completed_at TEXT,
                locked_by TEXT,
                locked_at TEXT,
                error_message TEXT,
                error_trace TEXT,
                request_id TEXT,
                created_at TEXT,
                updated_at TEXT
            )
        ');
        return $pdo;
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

    public function testCreateJobStoresPayload(): void
    {
        $pdo = $this->createSqlitePdo();
        $storage = new PdoJobStorage($pdo);

        $payload = ['user_id' => 123, 'action' => 'import'];
        $id = $storage->createJob('import.users', $payload);

        $job = $storage->find($id);
        $this->assertEquals($payload, $job->payload);
    }

    public function testClaimJobLocksProperly(): void
    {
        $pdo = $this->createSqlitePdo();
        $storage = new PdoJobStorage($pdo);

        $id = $storage->createJob('test.job', []);

        $claimed = $storage->claimJob($id, 'worker-1');
        $this->assertTrue($claimed);

        $job = $storage->find($id);
        $this->assertEquals('running', $job->status);
        $this->assertEquals('worker-1', $job->lockedBy);

        $claimedAgain = $storage->claimJob($id, 'worker-2');
        $this->assertFalse($claimedAgain, 'Should not claim already running job');
    }

    public function testMarkCompletedWithResult(): void
    {
        $pdo = $this->createSqlitePdo();
        $storage = new PdoJobStorage($pdo);

        $id = $storage->createJob('test.job', []);
        $storage->claimJob($id, 'worker-1');

        $result = ['imported' => 100, 'failed' => 5];
        $storage->markCompleted($id, $result);

        $job = $storage->find($id);
        $this->assertEquals('completed', $job->status);
        $this->assertEquals($result, $job->result);
    }

    public function testUpdateProgress(): void
    {
        $pdo = $this->createSqlitePdo();
        $storage = new PdoJobStorage($pdo);

        $id = $storage->createJob('test.job', []);
        $storage->claimJob($id, 'worker-1');

        $storage->updateProgress($id, 50, 'Halfway there');

        $job = $storage->find($id);
        $this->assertEquals(50, $job->progress);
        $this->assertEquals('Halfway there', $job->progressMessage);
    }

    public function testScheduleRetry(): void
    {
        $pdo = $this->createSqlitePdo();
        $storage = new PdoJobStorage($pdo);

        $id = $storage->createJob('test.job', []);
        $storage->claimJob($id, 'worker-1');

        $storage->scheduleRetry($id, 1, 60, 'Temporary failure');

        $job = $storage->find($id);
        $this->assertEquals('pending', $job->status);
        $this->assertEquals(1, $job->attempts);
        $this->assertNull($job->lockedBy);
        $this->assertNotNull($job->availableAt);
    }
}
