<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Integration;

use Oeltima\SimpleQueue\Contract\JobStatus;
use Oeltima\SimpleQueue\Driver\RedisQueueDriver;
use Oeltima\SimpleQueue\Storage\PdoJobStorage;
use Oeltima\SimpleQueue\Tests\DbHelper;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Predis\Client;

enum QueueServiceFixture
{
    case Redis;
    case Valkey;

    public function label(): string
    {
        return $this === self::Redis ? 'Redis' : 'Valkey';
    }

    public function hostVariable(): string
    {
        return $this === self::Redis ? 'REDIS_HOST' : 'VALKEY_HOST';
    }

    public function portVariable(): string
    {
        return $this === self::Redis ? 'REDIS_PORT' : 'VALKEY_PORT';
    }

    public function prefix(): string
    {
        return $this === self::Redis ? 'integration-test' : 'integration-test-valkey';
    }

    public function hasExtendedCoverage(): bool
    {
        return $this === self::Valkey;
    }
}

enum DatabaseServiceFixture
{
    case MySql;
    case PostgreSql;

    public function label(): string
    {
        return $this === self::MySql ? 'MySQL' : 'PostgreSQL';
    }

    public function dsnVariable(): string
    {
        return $this === self::MySql ? 'MYSQL_DSN' : 'POSTGRES_DSN';
    }

    public function userVariable(): string
    {
        return $this === self::MySql ? 'MYSQL_USER' : 'POSTGRES_USER';
    }

    public function passwordVariable(): string
    {
        return $this === self::MySql ? 'MYSQL_PASSWORD' : 'POSTGRES_PASSWORD';
    }

    public function table(): string
    {
        return $this === self::MySql ? 'test_mysql_jobs' : 'test_postgres_jobs';
    }

    public function concurrentTable(): string
    {
        return $this->table() . '_idempotent';
    }
}

final class RealServicesTest extends TestCase
{
    /** @return iterable<string, array{QueueServiceFixture}> */
    public static function queueServices(): iterable
    {
        yield 'Redis' => [QueueServiceFixture::Redis];
        yield 'Valkey' => [QueueServiceFixture::Valkey];
    }

    #[DataProvider('queueServices')]
    public function testRealQueueDriver(QueueServiceFixture $service): void
    {
        $host = getenv($service->hostVariable());
        if (!$host) {
            $this->markTestSkipped($service->hostVariable() . ' is not set. Skipping real integration test.');
        }
        $port = getenv($service->portVariable()) ?: '6379';
        $client = new Client(['scheme' => 'tcp', 'host' => $host, 'port' => (int) $port]);
        try {
            $client->connect();
        } catch (\Exception $exception) {
            $this->markTestSkipped('Could not connect to ' . $service->label() . ': ' . $exception->getMessage());
        }
        $driver = new RedisQueueDriver($client, $service->prefix());
        $this->verifyCoreQueueOperations($driver);
        if ($service->hasExtendedCoverage()) {
            $this->verifyExtendedQueueOperations($driver);
        }
        $driver->clear('default');
    }

    private function verifyCoreQueueOperations(RedisQueueDriver $driver): void
    {
        $driver->clear('default');
        $driver->enqueue('default', 42);
        $this->assertSame(1, $driver->getPendingCount('default'));
        $this->assertSame(42, $driver->dequeue('default', 0));
        $this->assertSame(0, $driver->getPendingCount('default'));
        $this->assertSame(1, $driver->getProcessingCount('default'));
        $driver->ack('default', 42);
        $this->assertSame(0, $driver->getProcessingCount('default'));
    }

    private function verifyExtendedQueueOperations(RedisQueueDriver $driver): void
    {
        $driver->enqueue('default', 99);
        $this->assertSame(99, $driver->dequeue('default', 1));
        $driver->ack('default', 99);
        $driver->nack('default', 101, 1);
        $this->assertSame(1, $driver->getDelayedCount('default'));
        sleep(2);
        $this->assertSame(1, $driver->promoteDelayedJobs('default'));
        $this->assertSame(1, $driver->getPendingCount('default'));
    }

    /** @return iterable<string, array{DatabaseServiceFixture}> */
    public static function databaseServices(): iterable
    {
        yield 'MySQL' => [DatabaseServiceFixture::MySql];
        yield 'PostgreSQL' => [DatabaseServiceFixture::PostgreSql];
    }

    #[DataProvider('databaseServices')]
    public function testRealStorage(DatabaseServiceFixture $service): void
    {
        $this->requireDatabaseService($service);
        try {
            $pdo = $this->connect($service);
        } catch (\Exception $exception) {
            $this->markTestSkipped('Could not connect to ' . $service->label() . ': ' . $exception->getMessage());
        }
        $this->runStorageTests($pdo, $service);
    }

    #[DataProvider('databaseServices')]
    public function testConcurrentIdempotentStorageCreation(DatabaseServiceFixture $service): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('Process control is unavailable.');
        }
        $this->requireDatabaseService($service);
        $files = $this->concurrencyFiles();
        $this->createConcurrentTable($service);
        try {
            $processIds = $this->spawnIdempotentChildren($service, $files);
            touch($files['barrier']->getPathname());
            $this->waitForChildren($processIds);
            $this->verifyConcurrentResults($service, $files['results']);
        } finally {
            $this->cleanupConcurrentRun($service, $files);
        }
    }

    private function runStorageTests(PDO $pdo, DatabaseServiceFixture $service): void
    {
        $table = $service->table();
        $pdo->exec("DROP TABLE IF EXISTS {$table}");
        DbHelper::createSchema($pdo, $table);
        $storage = new PdoJobStorage($pdo, $table);
        $jobId = $storage->createJob('test.job', ['foo' => 'bar'], 'default');
        $this->assertGreaterThan(0, $jobId);
        $job = $storage->find($jobId);
        $this->assertNotNull($job);
        $this->assertSame(JobStatus::Pending, $job->status);
        $this->assertSame(['foo' => 'bar'], $job->payload);
        $claim = $storage->claimNextAvailable('default', 'worker-1');
        $this->assertNotNull($claim);
        $this->assertSame($jobId, $claim->job->id);
        $this->assertSame('worker-1', $claim->workerId);
        $this->assertTrue($storage->heartbeat($claim));
        $this->assertTrue($storage->updateProgress($claim, 50, 'working'));
        $this->assertTrue($storage->markCompleted($claim, ['res' => 'ok']));
        $job = $storage->find($jobId);
        $this->assertSame(JobStatus::Completed, $job->status);
        $this->assertSame(['res' => 'ok'], $job->result);
        $this->verifyStorageSafety($storage);
        $pdo->exec("DROP TABLE IF EXISTS {$table}");
    }

    private function verifyStorageSafety(PdoJobStorage $storage): void
    {
        $first = $storage->createIdempotentJob('test.idempotent', [], 'request-1', 'default', 3);
        $second = $storage->createIdempotentJob('test.idempotent', [], 'request-1', 'default', 3);
        $this->assertTrue($first->created);
        $this->assertFalse($second->created);
        $this->assertSame($first->jobId, $second->jobId);
        $fencedId = $storage->createJob('test.fenced', []);
        $oldClaim = $storage->claimById($fencedId, 'worker-old');
        $this->assertNotNull($oldClaim);
        $this->assertTrue($storage->scheduleRetry($oldClaim, 1, 0));
        $replacementClaim = $storage->claimById($fencedId, 'worker-new');
        $this->assertNotNull($replacementClaim);
        $this->assertNotSame($oldClaim->leaseToken, $replacementClaim->leaseToken);
        $this->assertFalse($storage->heartbeat($oldClaim));
        $this->assertFalse($storage->markCompleted($oldClaim));
        $this->assertTrue($storage->markCompleted($replacementClaim));
    }

    private function requireDatabaseService(DatabaseServiceFixture $service): void
    {
        if (!getenv($service->dsnVariable())) {
            $this->markTestSkipped($service->dsnVariable() . ' is not set. Skipping real integration test.');
        }
    }

    private function connect(DatabaseServiceFixture $service): PDO
    {
        $dsn = getenv($service->dsnVariable()) ?: '';
        $user = getenv($service->userVariable()) ?: '';
        $password = getenv($service->passwordVariable()) ?: '';
        $pdo = new PDO($dsn, $user, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    }

    /** @return array{barrier: \SplFileInfo, results: list<\SplFileInfo>} */
    private function concurrencyFiles(): array
    {
        $barrier = $this->temporaryFile('sq_barrier_');
        unlink($barrier->getPathname());
        return [
            'barrier' => $barrier,
            'results' => [$this->temporaryFile('sq_result_'), $this->temporaryFile('sq_result_')],
        ];
    }

    private function temporaryFile(string $prefix): \SplFileInfo
    {
        $file = tempnam(sys_get_temp_dir(), $prefix);
        if ($file === false) {
            throw new \RuntimeException('Could not create concurrency file.');
        }
        return new \SplFileInfo($file);
    }

    private function createConcurrentTable(DatabaseServiceFixture $service): void
    {
        $pdo = $this->connect($service);
        $table = $service->concurrentTable();
        $pdo->exec("DROP TABLE IF EXISTS {$table}");
        DbHelper::createSchema($pdo, $table);
    }

    /**
     * @param array{barrier: \SplFileInfo, results: list<\SplFileInfo>} $files
     * @return list<int>
     */
    private function spawnIdempotentChildren(DatabaseServiceFixture $service, array $files): array
    {
        $processIds = [];
        foreach ($files['results'] as $resultFile) {
            $processId = pcntl_fork();
            self::assertNotSame(-1, $processId);
            if ($processId === 0) {
                $this->runIdempotentChild($service, $files['barrier'], $resultFile);
            }
            $processIds[] = $processId;
        }
        return $processIds;
    }

    /** @param list<int> $processIds */
    private function waitForChildren(array $processIds): void
    {
        foreach ($processIds as $processId) {
            self::assertSame($processId, pcntl_waitpid($processId, $status));
            self::assertTrue(pcntl_wifexited($status));
            self::assertSame(0, pcntl_wexitstatus($status));
        }
    }

    /** @param list<\SplFileInfo> $resultFiles */
    private function verifyConcurrentResults(DatabaseServiceFixture $service, array $resultFiles): void
    {
        $results = array_map($this->readConcurrentResult(...), $resultFiles);
        self::assertCount(1, array_filter($results, static fn(array $result): bool => $result['created']));
        self::assertCount(1, array_unique(array_column($results, 'job_id')));
        $statement = $this->connect($service)->query('SELECT COUNT(*) FROM ' . $service->concurrentTable());
        self::assertInstanceOf(\PDOStatement::class, $statement);
        self::assertSame(1, (int) $statement->fetchColumn());
    }

    /** @param array{barrier: \SplFileInfo, results: list<\SplFileInfo>} $files */
    private function cleanupConcurrentRun(DatabaseServiceFixture $service, array $files): void
    {
        $this->connect($service)->exec('DROP TABLE IF EXISTS ' . $service->concurrentTable());
        foreach ([$files['barrier'], ...$files['results']] as $file) {
            if ($file->isFile()) {
                unlink($file->getPathname());
            }
        }
    }

    private function runIdempotentChild(
        DatabaseServiceFixture $service,
        \SplFileInfo $barrier,
        \SplFileInfo $resultFile
    ): never {
        while (!$barrier->isFile()) {
            clearstatcache(true, $barrier->getPathname());
            usleep(1_000);
        }
        try {
            $storage = new PdoJobStorage($this->connect($service), $service->concurrentTable());
            $result = $storage->createIdempotentJob('test.concurrent', [], 'shared-request', 'default', 3);
            file_put_contents($resultFile->getPathname(), json_encode([
                'job_id' => $result->jobId,
                'created' => $result->created,
            ], JSON_THROW_ON_ERROR));
            exit(0);
        } catch (\Throwable $exception) {
            file_put_contents($resultFile->getPathname(), $exception->getMessage());
            exit(1);
        }
    }

    /** @return array{job_id: int, created: bool} */
    private function readConcurrentResult(\SplFileInfo $file): array
    {
        $contents = file_get_contents($file->getPathname());
        if ($contents === false) {
            throw new \RuntimeException('Could not read concurrency result.');
        }
        $result = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($result)) {
            throw new \RuntimeException('Concurrent child returned an invalid result.');
        }
        return [
            'job_id' => $this->concurrentJobId($result),
            'created' => $this->concurrentCreationState($result),
        ];
    }

    /** @param array<mixed> $result */
    private function concurrentJobId(array $result): int
    {
        $jobId = $result['job_id'] ?? null;
        if (!is_int($jobId)) {
            throw new \RuntimeException('Concurrent child returned an invalid job ID.');
        }
        return $jobId;
    }

    /** @param array<mixed> $result */
    private function concurrentCreationState(array $result): bool
    {
        $created = $result['created'] ?? null;
        if (!is_bool($created)) {
            throw new \RuntimeException('Concurrent child returned an invalid creation state.');
        }
        return $created;
    }
}
