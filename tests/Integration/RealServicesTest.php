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
        $dsn = getenv($dsnVariable);
        if (!$dsn) {
            $this->markTestSkipped("{$dsnVariable} is not set. Skipping real {$service} integration test.");
        }
        $user = getenv($userVariable) ?: '';
        $password = getenv($passwordVariable) ?: '';
        try {
            $pdo = new PDO($dsn, $user, $password);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (\Exception $e) {
            $this->markTestSkipped("Could not connect to {$service}: " . $e->getMessage());
        }
        $this->runStorageTests($pdo, $table);
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

        $pdo->exec("DROP TABLE IF EXISTS {$tableName}");
    }
}
