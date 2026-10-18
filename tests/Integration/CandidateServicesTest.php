<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Integration;

use Oeltima\SimpleQueue\Contract\IdempotentJobResult;
use Oeltima\SimpleQueue\Contract\PdoJobParticipantInterface;
use Oeltima\SimpleQueue\Contract\StorageTransition;
use Oeltima\SimpleQueue\Storage\PdoJobStorage;
use Oeltima\SimpleQueue\Tests\DbHelper;
use Oeltima\SimpleQueue\Tests\Support\DatabaseFixture;
use Oeltima\SimpleQueue\Tests\Support\FrozenClock;
use Oeltima\SimpleQueue\Tests\Support\RedisFixture;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CandidateServicesTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function databases(): iterable
    {
        yield 'MySQL' => ['MYSQL'];
        yield 'PostgreSQL' => ['POSTGRES'];
    }

    #[DataProvider('databases')]
    public function testIdempotentBatchesCompactQueriesAndTransactionalRecoveryOnRealDatabase(string $service): void
    {
        $pdo = DatabaseFixture::connect($service);
        $pdo->exec('DROP TABLE IF EXISTS candidate_jobs');
        $pdo->exec('DROP TABLE IF EXISTS candidate_outbox');
        DbHelper::createSchema($pdo, 'candidate_jobs');
        $pdo->exec('CREATE TABLE candidate_outbox (job_id BIGINT, status VARCHAR(20), attempts INT)');
        $clock = new FrozenClock();
        $participant = new class implements PdoJobParticipantInterface {
            public function beforeCreate(PDO $pdo, array $definitions): void
            {
            }
            public function afterTransition(PDO $pdo, StorageTransition $transition): void
            {
                $statement = $pdo->prepare('INSERT INTO candidate_outbox SELECT id, status, attempts FROM candidate_jobs WHERE id = ?');
                $statement->execute([$transition->jobId]);
            }
        };
        try {
            $storage = new PdoJobStorage($pdo, 'candidate_jobs', $clock, $participant);
            $jobs = [];
            for ($index = 0; $index < 201; $index++) {
                $jobs[] = ['type' => 'test', 'payload' => [], 'requestId' => 'batch:' . $index, 'maxAttempts' => 1];
            }
            $created = $storage->createIdempotentJobs($jobs);
            self::assertCount(201, $created);
            self::assertCount(201, array_filter($created, static fn (IdempotentJobResult $r): bool => $r->created));
            $existing = $storage->createIdempotentJobs($jobs);
            self::assertSame(array_column($created, 'jobId'), array_column($existing, 'jobId'));
            self::assertSame([], array_filter($existing, static fn (IdempotentJobResult $r): bool => $r->created));
            self::assertSame(201, $storage->countByStatus()['pending']);
            $ids = [$created[100]->jobId, $created[0]->jobId];
            self::assertSame($ids, array_keys($storage->findSummaries($ids)));
            self::assertCount(10, $storage->listSummaries(limit: 10));
            $claim = $storage->claimById($ids[0], 'worker');
            self::assertNotNull($claim);
            $clock->advance(601);
            self::assertSame(1, $storage->recoverStaleJobsForQueue('default', 600, 100));
            $statement = $pdo->query("SELECT attempts FROM candidate_outbox WHERE status = 'failed'");
            self::assertNotFalse($statement);
            self::assertSame(1, $statement->fetchColumn());
            self::assertFalse($storage->isClaimOwned($claim));
        } finally {
            $pdo->exec('DROP TABLE candidate_jobs');
            $pdo->exec('DROP TABLE candidate_outbox');
        }
    }

    public function testRealRedisSnapshotAndReconciliationWithLargeBoundedWindow(): void
    {
        $clock = new FrozenClock();
        $driver = RedisFixture::driver('candidate:' . bin2hex(random_bytes(6)), $clock);
        try {
            $driver->enqueueBatch('default', range(1, 500));
            $driver->enqueueDelayed('default', 501, $clock->timestamp() + 60);
            $snapshot = $driver->getStatsSnapshot('default');
            self::assertSame([500, 0, 1], [$snapshot->pending, $snapshot->processing, $snapshot->delayed]);
            $times = array_fill_keys(range(1, 1000), $clock->timestamp());
            $present = $driver->reconcileNotifications('default', $times, $clock->timestamp(), 1000000);
            self::assertSame(range(1, 501), $present);
            self::assertSame(999, $driver->getPendingCount('default'));
            self::assertSame(range(1, 1000), $driver->reconcileNotifications('default', $times, $clock->timestamp(), 1000000));
        } finally {
            $driver->clear('default');
        }
    }

    public function testRealRedisCapsThePendingSnapshotAndAllowsDuplicatesBeyondIt(): void
    {
        $clock = new FrozenClock();
        $driver = RedisFixture::driver('candidate-bound:' . bin2hex(random_bytes(6)), $clock);
        try {
            $driver->enqueueBatch('default', range(1, 10001));
            $times = [1 => $clock->timestamp()];
            self::assertSame([], $driver->reconcileNotifications('default', $times, $clock->timestamp(), 1000000));
            self::assertSame(10002, $driver->getPendingCount('default'));
            self::assertSame([1], $driver->reconcileNotifications('default', $times, $clock->timestamp(), 1000000));
            self::assertSame(10002, $driver->getPendingCount('default'));
        } finally {
            $driver->clear('default');
        }
    }

    #[DataProvider('databases')]
    public function testConcurrentIdempotentBatchesReturnTheSameWinningIds(string $service): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl is required for concurrent creation');
        }
        $pdo = DatabaseFixture::connect($service);
        $pdo->exec('DROP TABLE IF EXISTS candidate_race_jobs');
        DbHelper::createSchema($pdo, 'candidate_race_jobs');
        $pdo = null;
        $paths = [];
        $children = [];
        try {
            for ($index = 0; $index < 2; $index++) {
                $path = tempnam(sys_get_temp_dir(), 'queue-batch-race-');
                self::assertNotFalse($path);
                $paths[] = $path;
                $pid = pcntl_fork();
                self::assertNotSame(-1, $pid);
                if ($pid === 0) {
                    self::runBatchChild($service, $path);
                }
                $children[] = $pid;
            }
            $statuses = [];
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                $statuses[] = pcntl_wexitstatus($status);
            }
            $outcomes = [];
            foreach ($paths as $path) {
                $outcome = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
                self::assertIsArray($outcome);
                $outcomes[] = $outcome;
            }
            self::assertSame([0, 0], $statuses, json_encode($outcomes, JSON_THROW_ON_ERROR));
            self::assertSame(array_column($outcomes[0], 'jobId'), array_column($outcomes[1], 'jobId'));
            self::assertSame(20, array_sum(array_column($outcomes[0], 'created')) + array_sum(array_column($outcomes[1], 'created')));
        } finally {
            foreach ($paths as $path) {
                unlink($path);
            }
            DatabaseFixture::connect($service)->exec('DROP TABLE candidate_race_jobs');
        }
    }

    private static function runBatchChild(string $service, string $path): never
    {
        try {
            $storage = new PdoJobStorage(DatabaseFixture::connect($service), 'candidate_race_jobs');
            $jobs = [];
            for ($index = 0; $index < 20; $index++) {
                $jobs[] = ['type' => 'test', 'payload' => [], 'requestId' => 'race:' . $index];
            }
            usleep(100000);
            $results = $storage->createIdempotentJobs($jobs);
            file_put_contents($path, json_encode($results, JSON_THROW_ON_ERROR));
            exit(0);
        } catch (\Throwable $exception) {
            file_put_contents($path, json_encode(['error' => $exception->getMessage()], JSON_THROW_ON_ERROR));
            exit(1);
        }
    }
}
