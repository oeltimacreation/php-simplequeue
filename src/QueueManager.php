<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue;

use Oeltima\SimpleQueue\Contract\JobStorageInterface;
use Oeltima\SimpleQueue\Contract\QueueDriverInterface;
use Oeltima\SimpleQueue\Driver\DatabaseQueueDriver;
use Oeltima\SimpleQueue\Driver\RedisQueueDriver;
use Oeltima\SimpleQueue\Exception\DriverNotAvailableException;
use Predis\ClientInterface;

/**
 * Central manager for queue operations.
 *
 * Provides factory methods for creating queue managers with different
 * driver configurations and handles driver selection.
 */
final class QueueManager
{
    private QueueDriverInterface $driver;

    public function __construct(QueueDriverInterface $driver)
    {
        $this->driver = $driver;
    }

    /**
     * Get the underlying queue driver.
     */
    public function driver(): QueueDriverInterface
    {
        return $this->driver;
    }

    /**
     * Enqueue a job.
     *
     * @param int $jobId Job identifier
     * @param string $queue Queue name
     */
    public function enqueue(int $jobId, string $queue = 'default'): void
    {
        $this->driver->enqueue($queue, $jobId);
    }

    /**
     * Check if the queue driver is available.
     */
    public function isAvailable(): bool
    {
        return $this->driver->isAvailable();
    }

    /**
     * Create a QueueManager with automatic driver selection.
     *
     * Tries Redis first if available, falls back to database polling.
     *
     * @param string $driverName Driver name: 'redis', 'db', or 'auto'
     * @param ClientInterface|null $redis Redis client (optional)
     * @param JobStorageInterface|null $storage Job storage for DB fallback
     * @param string $redisPrefix Prefix for Redis keys
     * @return self
     */
    public static function create(
        string $driverName = 'auto',
        ?ClientInterface $redis = null,
        ?JobStorageInterface $storage = null,
        string $redisPrefix = 'simplequeue'
    ): self {
        $driverName = strtolower(trim($driverName));

        $redisDriver = null;
        if ($redis !== null) {
            $redisDriver = new RedisQueueDriver($redis, $redisPrefix);
        }

        $dbDriver = null;
        if ($storage !== null) {
            $dbDriver = new DatabaseQueueDriver($storage);
        }

        // Select driver based on configuration
        if ($driverName === 'redis' && $redisDriver !== null) {
            if ($redisDriver->isAvailable()) {
                return new self($redisDriver);
            }
            throw DriverNotAvailableException::redis();
        }

        if ($driverName === 'db' && $dbDriver !== null) {
            return new self($dbDriver);
        }

        // Auto mode: try Redis first, fallback to DB
        if ($driverName === 'auto') {
            if ($redisDriver !== null && $redisDriver->isAvailable()) {
                return new self($redisDriver);
            }
            if ($dbDriver !== null) {
                return new self($dbDriver);
            }
        }

        throw DriverNotAvailableException::noDriver();
    }

    /**
     * Create a QueueManager with Redis driver.
     *
     * @param ClientInterface $redis Redis client
     * @param string $prefix Prefix for Redis keys
     * @return self
     */
    public static function redis(ClientInterface $redis, string $prefix = 'simplequeue'): self
    {
        return new self(new RedisQueueDriver($redis, $prefix));
    }

    /**
     * Create a QueueManager with database polling driver.
     *
     * @param JobStorageInterface $storage Job storage implementation
     * @return self
     */
    public static function database(JobStorageInterface $storage): self
    {
        return new self(new DatabaseQueueDriver($storage));
    }
}
