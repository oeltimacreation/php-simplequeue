<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Integration;

use Oeltima\SimpleQueue\Contract\JobStatus;
use Oeltima\SimpleQueue\Storage\InMemoryJobStorage;
use Oeltima\SimpleQueue\Storage\PdoJobStorage;
use Oeltima\SimpleQueue\Tests\DbHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/benchmarks/benchmark-types.php';

final class CompactJobQueriesTest extends TestCase
{
    /** @return iterable<string, array{bool}> */
    public static function backends(): iterable
    {
        yield 'memory' => [false];
        yield 'SQLite' => [true];
    }

    #[DataProvider('backends')]
    public function testCompactCursorFilteringGroupedCountsAndOrderedBatchReads(bool $pdoBackend): void
    {
        $pdo = new \BenchmarkPdo('sqlite::memory:');
        DbHelper::createSchema($pdo);
        $storage = $pdoBackend ? new PdoJobStorage($pdo) : new InMemoryJobStorage();
        $one = $storage->createJob('first', ['data' => str_repeat('x', 1048576)]);
        $two = $storage->createJob('second', [], 'other');
        $three = $storage->createJob('third', []);
        $storage->cancel($three);
        if ($pdoBackend) {
            $pdo->exec("UPDATE background_jobs SET payload = '{broken', result = '{broken'");
        }
        $pdo->resetCounts();
        self::assertSame([$three, $two], array_column($storage->listSummaries(limit: 2), 'id'));
        self::assertSame([$one], array_column($storage->listSummaries(beforeId: $two), 'id'));
        self::assertSame([$three], array_column($storage->listSummaries(status: JobStatus::Cancelled), 'id'));
        self::assertSame([$three, $one], array_keys($storage->findSummaries([$three, 999, $one, $three])));
        self::assertSame([], $storage->findSummaries([]));
        self::assertSame(['pending' => 1, 'running' => 0, 'completed' => 0, 'failed' => 0, 'cancelled' => 1], $storage->countByStatus('default'));
        self::assertSame(0, $pdo->fetchedJsonBytes);
        self::assertSame(JobStatus::Pending, $storage->findSummaries([$one])[$one]->status);
    }

    #[DataProvider('backends')]
    public function testInvalidBatchIdDoesNotIssuePartialRead(bool $pdoBackend): void
    {
        $pdo = new \BenchmarkPdo('sqlite::memory:');
        DbHelper::createSchema($pdo);
        $storage = $pdoBackend ? new PdoJobStorage($pdo) : new InMemoryJobStorage();
        $pdo->resetCounts();
        try {
            $storage->findSummaries([1, -2]);
            self::fail('Invalid ID must reject the whole request');
        } catch (\InvalidArgumentException) {
            self::assertSame(0, $pdo->executions);
        }
    }
}
