<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Integration;

use Oeltima\SimpleQueue\Driver\RedisQueueDriver;
use Oeltima\SimpleQueue\Storage\PdoJobStorage;
use Oeltima\SimpleQueue\Tests\DbHelper;
use PDO;
use PHPUnit\Framework\TestCase;
use Predis\Client;

final class RealServicesTest extends TestCase
{
    public function testRealRedisDriver(): void
    {
        $redisHost = getenv('REDIS_HOST');
        if (!$redisHost) {
            $this->markTestSkipped('REDIS_HOST is not set. Skipping real Redis integration test.');
        }

        $redisPort = getenv('REDIS_PORT') ?: '6379';
        $client = new Client([
            'scheme' => 'tcp',
            'host' => $redisHost,
            'port' => (int) $redisPort,
        ]);

        try {
            $client->connect();
        } catch (\Exception $e) {
            $this->markTestSkipped('Could not connect to Redis: ' . $e->getMessage());
        }

        $driver = new RedisQueueDriver($client, 'integration-test');
        $driver->clear('default');

        // Test Enqueue
        $driver->enqueue('default', 42);
        $this->assertSame(1, $driver->getPendingCount('default'));

        // Test Dequeue
        $jobId = $driver->dequeue('default', 0);
        $this->assertSame(42, $jobId);
        $this->assertSame(0, $driver->getPendingCount('default'));
        $this->assertSame(1, $driver->getProcessingCount('default'));

        // Test Ack
        $driver->ack('default', 42);
        $this->assertSame(0, $driver->getProcessingCount('default'));

        $driver->clear('default');
    }

    public function testRealMySqlStorage(): void
    {
        $dsn = getenv('MYSQL_DSN');
        if (!$dsn) {
            $this->markTestSkipped('MYSQL_DSN is not set. Skipping real MySQL integration test.');
        }

        $user = getenv('MYSQL_USER') ?: '';
        $password = getenv('MYSQL_PASSWORD') ?: '';

        try {
            $pdo = new PDO($dsn, $user, $password);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (\Exception $e) {
            $this->markTestSkipped('Could not connect to MySQL: ' . $e->getMessage());
        }

        $this->runStorageTests($pdo, 'test_mysql_jobs');
    }

    public function testRealPostgresStorage(): void
    {
        $dsn = getenv('POSTGRES_DSN');
        if (!$dsn) {
            $this->markTestSkipped('POSTGRES_DSN is not set. Skipping real PostgreSQL integration test.');
        }

        $user = getenv('POSTGRES_USER') ?: '';
        $password = getenv('POSTGRES_PASSWORD') ?: '';

        try {
            $pdo = new PDO($dsn, $user, $password);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (\Exception $e) {
            $this->markTestSkipped('Could not connect to PostgreSQL: ' . $e->getMessage());
        }

        $this->runStorageTests($pdo, 'test_postgres_jobs');
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
        $this->assertSame('pending', $job->status);
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
        $this->assertSame('completed', $job->status);
        $this->assertSame(['res' => 'ok'], $job->result);

        $pdo->exec("DROP TABLE IF EXISTS {$tableName}");
    }
}
