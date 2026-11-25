<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Integration;

use Oeltima\SimpleQueue\Contract\JobStatus;
use Oeltima\SimpleQueue\Storage\PdoJobStorage;
use Oeltima\SimpleQueue\Tests\DbHelper;
use Oeltima\SimpleQueue\Tests\Support\FrozenClock;
use Oeltima\SimpleQueue\Tests\Support\SqliteFixture;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/benchmarks/benchmark-types.php';

final class LeanStorageReadsTest extends TestCase
{
    public function testClaimFetchesPayloadOnceWhilePreservingTransactionalBudget(): void
    {
        $pdo = new \BenchmarkPdo('sqlite::memory:');
        DbHelper::createSchema($pdo);
        $storage = new PdoJobStorage($pdo);
        $payload = ['data' => str_repeat('x', 1048576)];
        $id = $storage->createJob('test', $payload);
        $pdo->resetCounts();
        $claim = $storage->claimById($id, 'worker');
        self::assertNotNull($claim);
        self::assertSame($payload, $claim->job->payload);
        self::assertSame(strlen(json_encode($payload, JSON_THROW_ON_ERROR)), $pdo->fetchedJsonBytes);
        self::assertSame(3, $pdo->executions);
        self::assertSame(1, $pdo->transactions);
    }

    public function testRecoveryIgnoresPayloadCorruptionAndInvalidAttemptsWithoutStarvingHealthyJobs(): void
    {
        $pdo = new \BenchmarkPdo('sqlite::memory:');
        DbHelper::createSchema($pdo);
        $clock = new FrozenClock();
        $storage = new PdoJobStorage($pdo, clock: $clock);
        for ($index = 0; $index < 3; $index++) {
            $id = $storage->createJob('test', [], maxAttempts: 1);
            $storage->claimById($id, 'worker');
        }
        $pdo->exec("UPDATE background_jobs SET payload = '{broken', result = '{broken' WHERE id = 1");
        $pdo->exec('UPDATE background_jobs SET attempts = -1 WHERE id = 2');
        $clock->advance(601);
        $pdo->resetCounts();
        self::assertSame(2, $storage->recoverStaleJobsForQueue('default', 600, 2));
        self::assertSame(2, $pdo->executions);
        self::assertSame(0, $pdo->fetchedJsonBytes);
        $statement = $pdo->query('SELECT status FROM background_jobs ORDER BY id');
        self::assertNotFalse($statement);
        self::assertSame(['failed', 'running', 'failed'], $statement->fetchAll(\PDO::FETCH_COLUMN));
        self::assertSame(1, $storage->find(3)?->attempts);
    }

    public function testScopedRecoveryUsesOneUpdatePerChunkAndHonorsThePageLimit(): void
    {
        $pdo = new \BenchmarkPdo('sqlite::memory:');
        DbHelper::createSchema($pdo);
        $clock = new FrozenClock();
        $storage = new PdoJobStorage($pdo, clock: $clock);
        for ($index = 0; $index < 151; $index++) {
            $id = $storage->createJob('test', [], maxAttempts: $index % 2 === 0 ? 1 : 3);
            $storage->claimById($id, 'worker');
        }
        $clock->advance(601);
        $pdo->resetCounts();
        self::assertSame(150, $storage->recoverStaleJobsForQueue('default', 600, 150));
        self::assertSame(3, $pdo->executions);
        self::assertSame(0, $pdo->fetchedJsonBytes);
        self::assertSame(75, $storage->count(JobStatus::Pending));
        self::assertSame(75, $storage->count(JobStatus::Failed));
        self::assertSame(JobStatus::Running, $storage->find(151)?->status);
        self::assertSame(1, $storage->recoverStaleJobsForQueue('default', 600, 150));
    }

    public function testScopedRecoveryRechecksLeasesAfterSelectingCandidates(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'simplequeue-recovery-');
        self::assertNotFalse($path);
        try {
            $pdo = new RecoveryInterleavingPdo('sqlite:' . $path);
            DbHelper::createSchema($pdo);
            $clock = new FrozenClock();
            $storage = new PdoJobStorage($pdo, clock: $clock);
            $workerStorage = new PdoJobStorage(SqliteFixture::filePdo($path), clock: $clock);
            $heartbeat = $storage->claimById($storage->createJob('test', []), 'heartbeat');
            $completed = $storage->claimById($storage->createJob('test', []), 'completed');
            $stale = $storage->claimById($storage->createJob('test', []), 'stale');
            self::assertNotNull($heartbeat);
            self::assertNotNull($completed);
            self::assertNotNull($stale);
            $clock->advance(601);
            $pdo->beforeUpdate = static function () use ($workerStorage, $heartbeat, $completed): void {
                self::assertTrue($workerStorage->heartbeat($heartbeat));
                self::assertTrue($workerStorage->markCompleted($completed));
            };

            self::assertSame(1, $storage->recoverStaleJobsForQueue('default', 600, 100));
            self::assertTrue($storage->isClaimOwned($heartbeat));
            self::assertSame(JobStatus::Completed, $storage->find($completed->job->id)?->status);
            self::assertSame(JobStatus::Pending, $storage->find($stale->job->id)?->status);
        } finally {
            unlink($path);
        }
    }
}

final class RecoveryInterleavingPdo extends PDO
{
    public ?\Closure $beforeUpdate = null;

    /** @param array<array-key, mixed> $options */
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if ($this->beforeUpdate !== null && str_contains($query, 'WHERE id IN (')) {
            ($this->beforeUpdate)();
            $this->beforeUpdate = null;
        }
        return parent::prepare($query, $options);
    }
}
