<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Unit;

require_once __DIR__ . '/RedisQueueDriverTest.php';

use Oeltima\SimpleQueue\Driver\DatabaseQueueDriver;
use Oeltima\SimpleQueue\Driver\RedisQueueDriver;
use Oeltima\SimpleQueue\Exception\DriverNotAvailableException;
use Oeltima\SimpleQueue\QueueManager;
use Oeltima\SimpleQueue\Storage\InMemoryJobStorage;
use PHPUnit\Framework\TestCase;

class UnavailableRedisClient extends MockRedisClient
{
    public function __call($commandID, $arguments)
    {
        throw new \RuntimeException('Connection refused');
    }
}

class QueueManagerTest extends TestCase
{
    public function testCreateAutoPrefersRedisWhenAvailable(): void
    {
        $redis = new MockRedisClient();
        $redis->returns['ping'] = 'PONG';
        $storage = new InMemoryJobStorage();

        $manager = QueueManager::create('auto', $redis, $storage);

        $this->assertInstanceOf(RedisQueueDriver::class, $manager->driver());
    }

    public function testCreateAutoFallsBackToDbWhenRedisUnavailable(): void
    {
        $redis = new UnavailableRedisClient();
        $storage = new InMemoryJobStorage();

        $manager = QueueManager::create('auto', $redis, $storage);

        $this->assertInstanceOf(DatabaseQueueDriver::class, $manager->driver());
    }

    public function testCreateRedisExplicitWhenAvailable(): void
    {
        $redis = new MockRedisClient();
        $redis->returns['ping'] = 'PONG';

        $manager = QueueManager::create('redis', $redis);

        $this->assertInstanceOf(RedisQueueDriver::class, $manager->driver());
    }

    public function testCreateRedisThrowsWhenUnavailable(): void
    {
        $redis = new UnavailableRedisClient();

        $this->expectException(DriverNotAvailableException::class);

        QueueManager::create('redis', $redis);
    }

    public function testCreateDbExplicit(): void
    {
        $storage = new InMemoryJobStorage();

        $manager = QueueManager::create('db', storage: $storage);

        $this->assertInstanceOf(DatabaseQueueDriver::class, $manager->driver());
    }

    public function testCreateThrowsWhenNoDriverProvided(): void
    {
        $this->expectException(DriverNotAvailableException::class);

        QueueManager::create('auto', null, null);
    }

    public function testCreatePassesPollIntervalToDbDriver(): void
    {
        $storage = new InMemoryJobStorage();

        $manager = QueueManager::create('db', storage: $storage, pollIntervalMs: 500);

        $driver = $manager->driver();
        $this->assertInstanceOf(DatabaseQueueDriver::class, $driver);

        $ref = new \ReflectionProperty(DatabaseQueueDriver::class, 'pollIntervalMs');
        $this->assertEquals(500, $ref->getValue($driver));
    }

    public function testDatabaseFactoryMethodPassesPollInterval(): void
    {
        $storage = new InMemoryJobStorage();

        $manager = QueueManager::database($storage, 500);

        $driver = $manager->driver();
        $this->assertInstanceOf(DatabaseQueueDriver::class, $driver);

        $ref = new \ReflectionProperty(DatabaseQueueDriver::class, 'pollIntervalMs');
        $this->assertEquals(500, $ref->getValue($driver));
    }
}
