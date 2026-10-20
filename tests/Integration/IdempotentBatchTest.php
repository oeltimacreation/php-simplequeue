<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Integration;

use Oeltima\SimpleQueue\Driver\InMemoryQueueDriver;
use Oeltima\SimpleQueue\JobDispatcher;
use Oeltima\SimpleQueue\QueueManager;
use Oeltima\SimpleQueue\Storage\InMemoryJobStorage;
use Oeltima\SimpleQueue\Storage\PdoJobStorage;
use Oeltima\SimpleQueue\Tests\DbHelper;
use Oeltima\SimpleQueue\Tests\Support\FrozenClock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/benchmarks/benchmark-types.php';

final class IdempotentBatchTest extends TestCase
{
    /** @return iterable<string, array{bool}> */
    public static function backends(): iterable
    {
        yield 'memory' => [false];
        yield 'SQLite' => [true];
    }

    #[DataProvider('backends')]
    public function testMixedExistingNewAndRepeatedKeysKeepOrderAndSchedule(bool $pdoBackend): void
    {
        $clock = new FrozenClock();
        $pdo = new \BenchmarkPdo('sqlite::memory:');
        DbHelper::createSchema($pdo);
        $storage = $pdoBackend ? new PdoJobStorage($pdo, clock: $clock) : new InMemoryJobStorage($clock);
        $existing = $storage->createJob('old', [], 'original', 3, 'existing');
        $driver = new InMemoryQueueDriver($clock);
        $dispatcher = new JobDispatcher($storage, new QueueManager($driver), $clock);
        $results = $dispatcher->dispatchIdempotentBatch([
            ['type' => 'test', 'payload' => [], 'requestId' => 'new'],
            ['type' => 'ignored', 'payload' => [], 'requestId' => 'existing'],
            ['type' => 'test', 'payload' => [], 'requestId' => 'new'],
            ['type' => 'test', 'payload' => [], 'requestId' => 'scheduled', 'queue' => 'later', 'availableAt' => $clock->timestamp() + 60],
        ]);
        self::assertSame([2, $existing, 2, 3], array_column($results, 'jobId'));
        self::assertSame([true, false, false, true], array_column($results, 'created'));
        self::assertSame([2], $driver->getPending('default'));
        self::assertSame([3], $driver->getDelayedIds('later'));
        self::assertSame(3, $storage->count());
        $clock->advance(61);
        self::assertNotNull($storage->claimById(3, 'worker'));
    }

    #[DataProvider('backends')]
    public function testInvalidLaterDefinitionCannotPartiallyInsert(bool $pdoBackend): void
    {
        $pdo = new \BenchmarkPdo('sqlite::memory:');
        DbHelper::createSchema($pdo);
        $storage = $pdoBackend ? new PdoJobStorage($pdo) : new InMemoryJobStorage();
        try {
            $storage->createIdempotentJobs([
                ['type' => 'test', 'payload' => [], 'requestId' => 'valid'],
                ['type' => 'test', 'payload' => [], 'requestId' => ''],
            ]);
            self::fail('Every definition must validate before insertion');
        } catch (\InvalidArgumentException) {
            self::assertSame(0, $storage->count());
        }
    }

    public function testUncontendedBulkUsesChunkQueriesInsteadOfPerJobQueries(): void
    {
        $pdo = new \BenchmarkPdo('sqlite::memory:');
        DbHelper::createSchema($pdo);
        $storage = new PdoJobStorage($pdo);
        $jobs = [];
        for ($index = 0; $index < 201; $index++) {
            $jobs[] = ['type' => 'test', 'payload' => [], 'requestId' => 'request:' . $index];
        }
        $pdo->resetCounts();
        $results = $storage->createIdempotentJobs($jobs);
        self::assertCount(201, $results);
        self::assertLessThan(20, $pdo->executions);
        self::assertSame(1, $pdo->transactions);
        $pdo->beginTransaction();
        $storage->createIdempotentJobs([['type' => 'test', 'payload' => [], 'requestId' => 'caller']]);
        self::assertTrue($pdo->inTransaction());
        $pdo->rollBack();
        self::assertSame(201, $storage->count());
    }

    public function testDatabaseCollationAliasesFallBackAtomicallyWithoutLosingAvailability(): void
    {
        $pdo = new \BenchmarkPdo('sqlite::memory:');
        DbHelper::createSchema($pdo);
        $statement = $pdo->query("SELECT sql FROM sqlite_master WHERE name = 'background_jobs'");
        self::assertNotFalse($statement);
        $schema = $statement->fetchColumn();
        self::assertIsString($schema);
        $statement->closeCursor();
        $pdo->exec('DROP TABLE background_jobs');
        $pdo->exec(str_replace('request_id TEXT', 'request_id TEXT COLLATE NOCASE', $schema));
        $pdo->exec("CREATE UNIQUE INDEX active_request ON background_jobs (request_id) WHERE status IN ('pending', 'running')");
        $clock = new FrozenClock();
        $storage = new PdoJobStorage($pdo, clock: $clock);
        $jobs = [
            ['type' => 'test', 'payload' => [], 'requestId' => 'Alias', 'availableAt' => $clock->timestamp() + 60],
            ['type' => 'test', 'payload' => [], 'requestId' => 'alias', 'availableAt' => $clock->timestamp() + 60],
        ];
        $results = $storage->createIdempotentJobs($jobs);
        self::assertSame([true, false], array_column($results, 'created'));
        self::assertSame($results[0]->jobId, $results[1]->jobId);
        self::assertNull($storage->claimById($results[0]->jobId, 'too-early'));
        self::assertSame(1, $storage->count());
    }
}
