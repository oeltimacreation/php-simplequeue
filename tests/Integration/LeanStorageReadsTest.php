<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Integration;

use Oeltima\SimpleQueue\Storage\PdoJobStorage;
use Oeltima\SimpleQueue\Tests\DbHelper;
use Oeltima\SimpleQueue\Tests\Support\FrozenClock;
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
        self::assertSame(0, $pdo->fetchedJsonBytes);
        $statement = $pdo->query('SELECT status FROM background_jobs ORDER BY id');
        self::assertNotFalse($statement);
        self::assertSame(['failed', 'running', 'failed'], $statement->fetchAll(\PDO::FETCH_COLUMN));
        self::assertSame(1, $storage->find(3)?->attempts);
    }
}
