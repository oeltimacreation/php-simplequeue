<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue;

use Oeltima\SimpleQueue\Contract\DelayedBatch;
use Oeltima\SimpleQueue\Contract\JobStorageInterface;
use Oeltima\SimpleQueue\Contract\QueueDriverInterface;
use Oeltima\SimpleQueue\Contract\SupportsDelayedJobs;
use Oeltima\SimpleQueue\Contract\SupportsStorageBackedScheduling;
use Oeltima\SimpleQueue\Driver\DatabaseQueueDriver;
use Oeltima\SimpleQueue\Driver\RedisQueueDriver;
use Oeltima\SimpleQueue\Exception\DriverNotAvailableException;
use Oeltima\SimpleQueue\Exception\QueueException;
use Predis\ClientInterface;

/**
 * Central manager for queue operations.
 *
 * Provides factory methods for creating queue managers with different
 * driver configurations and handles driver selection.
 */
final class QueueManager
{
    public function __construct(
        private readonly QueueDriverInterface $driver
    ) {
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
     * Enqueue a job with a delayed notification.
     *
     * Drivers that support delayed notifications (Redis, InMemory) schedule the
     * job in their delayed structure. Storage-backed drivers such as Database
     * fall back to a plain enqueue, because their claims already enforce the
     * job's stored availability timestamp.
     *
     * Third-party drivers implementing neither SupportsDelayedJobs nor
     * SupportsStorageBackedScheduling receive a QueueException before any
     * storage mutation.
     *
     * @param int $jobId Job identifier
     * @param string $queue Queue name
     * @param int $availableAt Unix timestamp when the job becomes available
     */
    public function enqueueDelayed(int $jobId, string $queue, int $availableAt): void
    {
        $this->enqueueDelayedBatch(new DelayedBatch([$jobId], $queue, $availableAt));
    }

    /**
     * Enqueue multiple jobs with a delayed notification in one roundtrip.
     *
     * Delayed-capable drivers batch the notifications; storage-backed drivers
     * fall back to one plain enqueue per job. See {@see enqueueDelayed()} for
     * the third-party driver guidance.
     *
     * @param DelayedBatch $batch Jobs, queue, and availability time to notify
     */
    public function enqueueDelayedBatch(DelayedBatch $batch): void
    {
        $delayedDriver = $this->delayedDriver();
        if ($delayedDriver !== null) {
            $delayedDriver->enqueueDelayedBatch($batch->queue, $batch->jobIds, $batch->availableAt);
            return;
        }
        if ($this->driver instanceof SupportsStorageBackedScheduling) {
            foreach ($batch->jobIds as $jobId) {
                $this->enqueue($jobId, $batch->queue);
            }
            return;
        }

        throw new QueueException('Driver does not support scheduled dispatch for future jobs');
    }

    /**
     * The active driver when it can schedule delayed notifications natively.
     */
    private function delayedDriver(): ?SupportsDelayedJobs
    {
        return $this->driver instanceof SupportsDelayedJobs ? $this->driver : null;
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
     * @param int $pollIntervalMs Polling interval in milliseconds for DB driver
     */
    public static function create(
        string $driverName = 'auto',
        #[\SensitiveParameter] ?ClientInterface $redis = null,
        ?JobStorageInterface $storage = null,
        string $redisPrefix = 'simplequeue',
        int $pollIntervalMs = 250
    ): self {
        $driverName = strtolower(trim($driverName));

        $redisDriver = $redis !== null ? new RedisQueueDriver($redis, $redisPrefix) : null;
        $dbDriver = $storage !== null ? new DatabaseQueueDriver($storage, $pollIntervalMs) : null;

        $driver = match ($driverName) {
            'redis' => ($redisDriver !== null && $redisDriver->isAvailable())
                ? $redisDriver
                : throw DriverNotAvailableException::redis(),
            'db' => $dbDriver ?? throw DriverNotAvailableException::noDriver(),
            'auto' => ($redisDriver !== null && $redisDriver->isAvailable())
                ? $redisDriver
                : ($dbDriver ?? throw DriverNotAvailableException::noDriver()),
            default => throw DriverNotAvailableException::noDriver(),
        };

        return new self($driver);
    }

    /**
     * Create a QueueManager with Redis driver.
     *
     * @param ClientInterface $redis Redis client
     * @param string $prefix Prefix for Redis keys
     */
    public static function redis(#[\SensitiveParameter] ClientInterface $redis, string $prefix = 'simplequeue'): self
    {
        return new self(new RedisQueueDriver($redis, $prefix));
    }

    /**
     * Create a QueueManager with database polling driver.
     *
     * @param JobStorageInterface $storage Job storage implementation
     * @param int $pollIntervalMs Polling interval in milliseconds
     */
    public static function database(JobStorageInterface $storage, int $pollIntervalMs = 250): self
    {
        return new self(new DatabaseQueueDriver($storage, $pollIntervalMs));
    }
}
