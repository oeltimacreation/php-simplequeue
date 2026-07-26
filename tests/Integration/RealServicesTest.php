<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Integration;

use Oeltima\SimpleQueue\Driver\RedisQueueDriver;
use Oeltima\SimpleQueue\Storage\PdoJobStorage;
use Oeltima\SimpleQueue\Contract\JobStatus;
use Oeltima\SimpleQueue\Tests\DbHelper;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Predis\Client;

final class RealServicesTest extends TestCase
{
    /** @return iterable<string, array{string, string, string, string, bool}> */
    public static function queueServices(): iterable
    {
        yield 'Redis' => ['Redis', 'REDIS_HOST', 'REDIS_PORT', 'integration-test', false];
        yield 'Valkey' => ['Valkey', 'VALKEY_HOST', 'VALKEY_PORT', 'integration-test-valkey', true];
    }

    #[DataProvider('queueServices')]
    public function testRealQueueDriver(
        string $service,
        string $hostVariable,
        string $portVariable,
        string $prefix,
        bool $extended
    ): void {
        $host = getenv($hostVariable);
        if (!$host) {
            $this->markTestSkipped("{$hostVariable} is not set. Skipping real {$service} integration test.");
        }
        $port = getenv($portVariable) ?: '6379';
        $client = new Client([
            'scheme' => 'tcp',
            'host' => $host,
            'port' => (int) $port,
        ]);
        try {
            $client->connect();
        } catch (\Exception $e) {
            $this->markTestSkipped("Could not connect to {$service}: " . $e->getMessage());
        }
        $driver = new RedisQueueDriver($client, $prefix);
        $driver->clear('default');
        $driver->enqueue('default', 42);
        $this->assertSame(1, $driver->getPendingCount('default'));
        $jobId = $driver->dequeue('default', 0);
        $this->assertSame(42, $jobId);
        $this->assertSame(0, $driver->getPendingCount('default'));
        $this->assertSame(1, $driver->getProcessingCount('default'));
        $driver->ack('default', 42);
        $this->assertSame(0, $driver->getProcessingCount('default'));
        if (!$extended) {
            $driver->clear('default');
            return;
        }
        $driver->enqueue('default', 99);
        $jobId = $driver->dequeue('default', 1);
        $this->assertSame(99, $jobId);
        $driver->ack('default', 99);
        $driver->nack('default', 101, 1);
        $this->assertSame(1, $driver->getDelayedCount('default'));
        sleep(2);
        $this->assertSame(1, $driver->promoteDelayedJobs('default'));
        $this->assertSame(1, $driver->getPendingCount('default'));
        $driver->clear('default');
    }

    /** @return iterable<string, array{string, string, string, string, string}> */
    public static function databaseServices(): iterable
    {
        yield 'MySQL' => ['MySQL', 'MYSQL_DSN', 'MYSQL_USER', 'MYSQL_PASSWORD', 'test_mysql_jobs'];
        yield 'PostgreSQL' => [
            'PostgreSQL',
            'POSTGRES_DSN',
            'POSTGRES_USER',
            'POSTGRES_PASSWORD',
            'test_postgres_jobs',
        ];
    }

    #[DataProvider('databaseServices')]
    public function testRealStorage(
        string $service,
        string $dsnVariable,
        string $userVariable,
        string $passwordVariable,
        string $table
    ): void {
        [$dsn, $user, $password] = $this->databaseConfiguration(
            $service,
            $dsnVariable,
            $userVariable,
            $passwordVariable
        );
        try {
            $pdo = new PDO($dsn, $user, $password);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (\Exception $e) {
            $this->markTestSkipped("Could not connect to {$service}: " . $e->getMessage());
        }
        $this->runStorageTests($pdo, $table);
    }

    #[DataProvider('databaseServices')]
    public function testConcurrentIdempotentStorageCreation(
        string $service,
        string $dsnVariable,
        string $userVariable,
        string $passwordVariable,
        string $table
    ): void {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('Process control is unavailable.');
        }
        [$dsn, $user, $password] = $this->databaseConfiguration(
            $service,
            $dsnVariable,
            $userVariable,
            $passwordVariable
        );
        $table .= '_idempotent';
        $setup = $this->connect($dsn, $user, $password);
        $setup->exec("DROP TABLE IF EXISTS {$table}");
        DbHelper::createSchema($setup, $table);
        unset($setup);
        $barrier = tempnam(sys_get_temp_dir(), 'sq_barrier_');
        self::assertNotFalse($barrier);
        unlink($barrier);
        $resultFiles = $this->temporaryResultFiles(2);
        $processIds = [];

        try {
            foreach ($resultFiles as $resultFile) {
                $processId = pcntl_fork();
                self::assertNotSame(-1, $processId);
                if ($processId === 0) {
                    $this->runIdempotentChild($dsn, $user, $password, $table, $barrier, $resultFile);
                }
                $processIds[] = $processId;
            }
            touch($barrier);
            foreach ($processIds as $processId) {
                self::assertSame($processId, pcntl_waitpid($processId, $status));
                self::assertTrue(pcntl_wifexited($status));
                self::assertSame(0, pcntl_wexitstatus($status));
            }
            $results = array_map($this->readConcurrentResult(...), $resultFiles);
            self::assertCount(1, array_filter($results, static fn(array $result): bool => $result['created']));
            self::assertCount(1, array_unique(array_column($results, 'job_id')));
            $verification = $this->connect($dsn, $user, $password);
            $countStatement = $verification->query("SELECT COUNT(*) FROM {$table}");
            self::assertInstanceOf(\PDOStatement::class, $countStatement);
            self::assertSame(1, (int) $countStatement->fetchColumn());
        } finally {
            $this->connect($dsn, $user, $password)->exec("DROP TABLE IF EXISTS {$table}");
            foreach ([$barrier, ...$resultFiles] as $file) {
                if (file_exists($file)) {
                    unlink($file);
                }
            }
        }
    }

    private function runStorageTests(PDO $pdo, string $tableName): void
    {
        $pdo->exec("DROP TABLE IF EXISTS {$tableName}");
        DbHelper::createSchema($pdo, $tableName);

        $storage = new PdoJobStorage($pdo, $tableName);

        // Test Create
        $jobId = $storage->createJob('test.job', ['foo' => 'bar'], 'default');
        $this->assertGreaterThan(0, $jobId);

        $job = $storage->find($jobId);
        $this->assertNotNull($job);
        $this->assertSame(JobStatus::Pending, $job->status);
        $this->assertSame(['foo' => 'bar'], $job->payload);

        // Test Claim
        $claim = $storage->claimNextAvailable('default', 'worker-1');
        $this->assertNotNull($claim);
        $this->assertSame($jobId, $claim->job->id);
        $this->assertSame('worker-1', $claim->workerId);

        // Test Heartbeat
        $this->assertTrue($storage->heartbeat($claim));

        // Test Progress
        $this->assertTrue($storage->updateProgress($claim, 50, 'working'));

        // Test Complete
        $this->assertTrue($storage->markCompleted($claim, ['res' => 'ok']));

        $job = $storage->find($jobId);
        $this->assertSame(JobStatus::Completed, $job->status);
        $this->assertSame(['res' => 'ok'], $job->result);
        $this->verifyStorageSafety($storage);
        $pdo->exec("DROP TABLE IF EXISTS {$tableName}");
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

    /** @return array{string, string, string} */
    private function databaseConfiguration(
        string $service,
        string $dsnVariable,
        string $userVariable,
        string $passwordVariable
    ): array {
        $dsn = getenv($dsnVariable);
        if (!$dsn) {
            $this->markTestSkipped("{$dsnVariable} is not set. Skipping real {$service} integration test.");
        }
        return [$dsn, getenv($userVariable) ?: '', getenv($passwordVariable) ?: ''];
    }

    private function connect(string $dsn, string $user, string $password): PDO
    {
        $pdo = new PDO($dsn, $user, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    }

    /** @return list<string> */
    private function temporaryResultFiles(int $count): array
    {
        $files = [];
        for ($index = 0; $index < $count; $index++) {
            $file = tempnam(sys_get_temp_dir(), 'sq_result_');
            if ($file === false) {
                throw new \RuntimeException('Could not create concurrency result file.');
            }
            $files[] = $file;
        }
        return $files;
    }

    private function runIdempotentChild(
        string $dsn,
        string $user,
        string $password,
        string $table,
        string $barrier,
        string $resultFile
    ): never {
        while (!file_exists($barrier)) {
            usleep(1_000);
        }
        try {
            $storage = new PdoJobStorage($this->connect($dsn, $user, $password), $table);
            $result = $storage->createIdempotentJob('test.concurrent', [], 'shared-request', 'default', 3);
            file_put_contents($resultFile, json_encode([
                'job_id' => $result->jobId,
                'created' => $result->created,
            ], JSON_THROW_ON_ERROR));
            exit(0);
        } catch (\Throwable $exception) {
            file_put_contents($resultFile, $exception->getMessage());
            exit(1);
        }
    }

    /** @return array{job_id: int, created: bool} */
    private function readConcurrentResult(string $file): array
    {
        $contents = file_get_contents($file);
        if ($contents === false) {
            throw new \RuntimeException('Could not read concurrency result.');
        }
        $result = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($result) || !is_int($result['job_id'] ?? null) || !is_bool($result['created'] ?? null)) {
            throw new \RuntimeException('Concurrent child returned an invalid result.');
        }
        return ['job_id' => $result['job_id'], 'created' => $result['created']];
    }
}
