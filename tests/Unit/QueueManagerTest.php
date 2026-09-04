<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Unit;

require_once __DIR__ . '/RedisQueueDriverTest.php';

use Oeltima\SimpleQueue\Contract\DelayedBatch;
use Oeltima\SimpleQueue\Driver\DatabaseQueueDriver;
use Oeltima\SimpleQueue\Driver\InMemoryQueueDriver;
use Oeltima\SimpleQueue\Driver\RedisQueueDriver;
use Oeltima\SimpleQueue\Exception\DriverNotAvailableException;
use Oeltima\SimpleQueue\QueueManager;
use Oeltima\SimpleQueue\Storage\InMemoryJobStorage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UnavailableRedisClient extends MockRedisClient
{
    /**
     * @param string $commandID Command identifier
     * @param array<array-key, mixed> $arguments Command arguments
     */
    public function __call(mixed $commandID, mixed $arguments): mixed
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

        self::assertInstanceOf(RedisQueueDriver::class, $manager->driver());
    }

    public function testCreateAutoFallsBackToDbWhenRedisUnavailable(): void
    {
        $redis = new UnavailableRedisClient();
        $storage = new InMemoryJobStorage();

        $manager = QueueManager::create('auto', $redis, $storage);

        self::assertInstanceOf(DatabaseQueueDriver::class, $manager->driver());
    }

    public function testCreateRedisExplicitWhenAvailable(): void
    {
        $redis = new MockRedisClient();
        $redis->returns['ping'] = 'PONG';

        $manager = QueueManager::create('redis', $redis);

        self::assertInstanceOf(RedisQueueDriver::class, $manager->driver());
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

        self::assertInstanceOf(DatabaseQueueDriver::class, $manager->driver());
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
        self::assertInstanceOf(DatabaseQueueDriver::class, $driver);

        $ref = new \ReflectionProperty(DatabaseQueueDriver::class, 'pollIntervalMs');
        self::assertEquals(500, $ref->getValue($driver));
    }

    public function testDatabaseFactoryMethodPassesPollInterval(): void
    {
        $storage = new InMemoryJobStorage();

        $manager = QueueManager::database($storage, 500);

        $driver = $manager->driver();
        self::assertInstanceOf(DatabaseQueueDriver::class, $driver);

        $ref = new \ReflectionProperty(DatabaseQueueDriver::class, 'pollIntervalMs');
        self::assertEquals(500, $ref->getValue($driver));
    }

    public function testManagerAndBuiltInDriversReportAvailability(): void
    {
        self::assertTrue((new QueueManager(new InMemoryQueueDriver()))->isAvailable());
        self::assertTrue(QueueManager::database(new InMemoryJobStorage())->isAvailable());
    }

    public function testRedisFactoryBuildsDriverWithoutAvailabilityProbe(): void
    {
        $redis = new MockRedisClient();

        $manager = QueueManager::redis($redis, 'custom');

        self::assertInstanceOf(RedisQueueDriver::class, $manager->driver());
        self::assertSame([], $redis->calls);
    }

    public function testEnqueueDelayedDelegatesToDelayedSupportingDriver(): void
    {
        $driver = new InMemoryQueueDriver();
        $manager = new QueueManager($driver);

        $manager->enqueueDelayed(7, 'default', 1_700_000_100);

        $this->assertDelayedDelegation($driver, 1);
        self::assertSame(1_700_000_100, $driver->getDelayed('default')[7]);
    }

    #[DataProvider('invalidDelayedEnqueue')]
    public function testDelayedEnqueueRejectsInvalidJobIdForStorageGatedDriver(callable $enqueue): void
    {
        $manager = QueueManager::database(new InMemoryJobStorage());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('jobId must be a positive integer');
        $enqueue($manager);
    }

    public function testEnqueueDelayedBatchDelegatesToDelayedSupportingDriver(): void
    {
        $driver = new InMemoryQueueDriver();
        $manager = new QueueManager($driver);

        $manager->enqueueDelayedBatch(new DelayedBatch([7, 8], 'default', 1_700_000_100));

        $this->assertDelayedDelegation($driver, 2);
        self::assertSame(1_700_000_100, $driver->getDelayed('default')[7]);
        self::assertSame(1_700_000_100, $driver->getDelayed('default')[8]);
    }

    /**
     * @return array<string, array{callable(QueueManager): void}>
     */
    public static function invalidDelayedEnqueue(): array
    {
        return [
            'single' => [static fn (QueueManager $manager) => $manager->enqueueDelayed(0, 'default', 1_700_000_100)],
            'batch' => [static fn (QueueManager $manager) => $manager->enqueueDelayedBatch(new DelayedBatch([0], 'default', 1_700_000_100))],
        ];
    }

    private function assertDelayedDelegation(InMemoryQueueDriver $driver, int $expectedDelayedCount): void
    {
        self::assertSame(0, $driver->getPendingCount('default'));
        self::assertSame($expectedDelayedCount, $driver->getDelayedCount('default'));
    }
}
