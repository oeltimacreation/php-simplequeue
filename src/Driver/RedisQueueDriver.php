<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Driver;

use Oeltima\SimpleQueue\Contract\QueueDriverInterface;
use Oeltima\SimpleQueue\Contract\SupportsDelayedJobs;
use Oeltima\SimpleQueue\Contract\SupportsStaleRecovery;
use Oeltima\SimpleQueue\Contract\SupportsBatchEnqueue;
use Oeltima\SimpleQueue\Contract\SupportsTimeoutValidation;
use Oeltima\SimpleQueue\Contract\SupportsQueueReconciliation;
use Oeltima\SimpleQueue\Contract\QueueStatsInterface;
use Oeltima\SimpleQueue\Contract\SupportsJobRemoval;
use Oeltima\SimpleQueue\Contract\SupportsProcessingHeartbeat;
use Oeltima\SimpleQueue\Contract\SupportsBoundedQueueMembership;
use Oeltima\SimpleQueue\Contract\ClockInterface;
use Oeltima\SimpleQueue\Exception\QueueException;
use Oeltima\SimpleQueue\SystemClock;
use Predis\ClientInterface;

/**
 * Redis-based queue driver using list operations.
 *
 * This driver uses Redis lists with LMOVE and BLMOVE (introduced in Redis 6.2)
 * for reliable queue processing with at-least-once delivery guarantees.
 * Requires Redis >= 7.0 or Valkey >= 8.0.
 */
final class RedisQueueDriver implements
    QueueDriverInterface,
    SupportsDelayedJobs,
    SupportsStaleRecovery,
    SupportsBatchEnqueue,
    SupportsTimeoutValidation,
    SupportsQueueReconciliation,
    QueueStatsInterface,
    SupportsJobRemoval,
    SupportsProcessingHeartbeat,
    SupportsBoundedQueueMembership
{
    private const DEQUEUE_LUA = <<<'LUA'
local jobId = redis.call('LMOVE', KEYS[1], KEYS[2], 'RIGHT', 'LEFT')
if jobId then
    redis.call('ZADD', KEYS[3], ARGV[1], jobId)
end
return jobId
LUA;
    private const PROMOTE_DELAYED_LUA = <<<'LUA'
local delayedKey = KEYS[1]
local pendingKey = KEYS[2]
local now = tonumber(ARGV[1])
local limit = tonumber(ARGV[2])

local dueJobs = redis.call('ZRANGEBYSCORE', delayedKey, '-inf', now, 'LIMIT', 0, limit)
if #dueJobs > 0 then
    redis.call('LPUSH', pendingKey, unpack(dueJobs))
    redis.call('ZREM', delayedKey, unpack(dueJobs))
end
return #dueJobs
LUA;

    private const RECOVER_STALE_LUA = <<<'LUA'
local processingZKey = KEYS[1]
local processingKey = KEYS[2]
local pendingKey = KEYS[3]
local staleThreshold = tonumber(ARGV[1])
local limit = tonumber(ARGV[2])

local staleJobs = redis.call('ZRANGEBYSCORE', processingZKey, '-inf', staleThreshold, 'LIMIT', 0, limit)
if #staleJobs > 0 then
    for _, jobId in ipairs(staleJobs) do
        redis.call('LREM', processingKey, 1, jobId)
    end
    redis.call('LPUSH', pendingKey, unpack(staleJobs))
    redis.call('ZREM', processingZKey, unpack(staleJobs))
end
return #staleJobs
LUA;

    /** @var array<string, int> */
    private array $repairCursors = [];

    /**
     * @param ClientInterface $redis Predis client instance
     * @param string $prefix Key prefix for all queue keys
     */
    public function __construct(
        #[\SensitiveParameter] private ClientInterface $redis,
        private string $prefix = 'simplequeue',
        private readonly ClockInterface $clock = new SystemClock()
    ) {
    }

    public function isAvailable(): bool
    {
        try {
            $this->redis->ping();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Validate that the poll timeout is safe relative to the Redis read/write timeout.
     *
     * @param int $pollTimeout Seconds the worker will block waiting for a job
     * @throws \InvalidArgumentException If the timeout configuration is unsafe
     */
    public function validateTimeout(int $pollTimeout): void
    {
        try {
            $connection = $this->redis->getConnection();

            // @phpstan-ignore-next-line
            if (method_exists($connection, 'getParameters')) {
                // @phpstan-ignore-next-line
                $parameters = $connection->getParameters();
                if (isset($parameters->read_write_timeout)) {
                    $rwTimeout = $parameters->read_write_timeout;
                    if ($rwTimeout > 0) {
                        if ($pollTimeout >= $rwTimeout) {
                            throw new \InvalidArgumentException(sprintf(
                                'Unsafe timeout configuration: poll_timeout (%ds) must be strictly less than ' .
                                'Predis read_write_timeout (%ds) to prevent connection dropped errors.',
                                $pollTimeout,
                                $rwTimeout
                            ));
                        }
                    }
                }
            }
        } catch (\InvalidArgumentException $e) {
            throw $e;
        } catch (\Throwable) {
            // Fallback for custom/mock connections
        }
    }

    public function enqueue(string $queue, int $jobId): void
    {
        $this->validateJobId($jobId);
        $this->redis->lpush($this->pendingKey($queue), [(string) $jobId]);
    }

    public function dequeue(string $queue, int $timeoutSeconds): ?int
    {
        if ($timeoutSeconds < 0) {
            throw new \InvalidArgumentException('Dequeue timeout must not be negative');
        }
        if ($timeoutSeconds <= 0) {
            $result = $this->redis->eval(
                self::DEQUEUE_LUA,
                3,
                $this->pendingKey($queue),
                $this->processingKey($queue),
                $this->processingZKey($queue),
                (string) $this->clock->timestamp()
            );
        } else {
            // Blocking with timeout
            $result = $this->redis->blmove(
                $this->pendingKey($queue),
                $this->processingKey($queue),
                'RIGHT',
                'LEFT',
                $timeoutSeconds
            );
        }

        if ($result === null || $result === false || $result === '') {
            return null;
        }

        if (!is_string($result) || !$this->isValidRedisJobId($result)) {
            $this->discardMalformedProcessingNotification($queue, is_scalar($result) ? (string) $result : '');
            throw new QueueException('Redis returned a malformed queue job ID');
        }

        $jobId = (int) $result;

        if ($timeoutSeconds > 0) {
            // BLMOVE cannot be wrapped in Lua; repair handles its crash window.
            $this->redis->zadd($this->processingZKey($queue), [$jobId => $this->clock->timestamp()]);
        }

        return $jobId;
    }

    public function ack(string $queue, int $jobId): void
    {
        $this->validateJobId($jobId);
        /** @var \Predis\Pipeline\Pipeline $pipe */
        $pipe = $this->redis->pipeline();
        $pipe->lrem($this->processingKey($queue), 1, (string) $jobId);
        $pipe->zrem($this->processingZKey($queue), (string) $jobId);
        $pipe->execute();
    }

    public function remove(string $queue, int $jobId): void
    {
        $this->validateJobId($jobId);
        $id = (string) $jobId;
        $this->redis->eval(
            "redis.call('LREM', KEYS[1], 0, ARGV[1]); redis.call('ZREM', KEYS[2], ARGV[1]); " .
            "redis.call('LREM', KEYS[3], 0, ARGV[1]); redis.call('ZREM', KEYS[4], ARGV[1]); return 1",
            4,
            $this->pendingKey($queue),
            $this->delayedKey($queue),
            $this->processingKey($queue),
            $this->processingZKey($queue),
            $id
        );
    }

    public function heartbeatProcessing(string $queue, int $jobId): void
    {
        $this->validateJobId($jobId);
        $this->redis->zadd($this->processingZKey($queue), [$jobId => $this->clock->timestamp()]);
    }

    public function hasPendingJob(string $queue, int $jobId, int $maxElements): bool
    {
        if ($maxElements < 1) {
            throw new \InvalidArgumentException('Membership scan limit must be positive');
        }
        return $this->redis->lpos($this->pendingKey($queue), (string) $jobId, 'MAXLEN', $maxElements) !== null;
    }

    public function hasDelayedJob(string $queue, int $jobId): bool
    {
        return $this->redis->zscore($this->delayedKey($queue), (string) $jobId) !== null;
    }

    public function nack(string $queue, int $jobId, int $delaySeconds = 0): void
    {
        $this->validateJobId($jobId);
        if ($delaySeconds < 0) {
            throw new \InvalidArgumentException('Retry delay must not be negative');
        }
        /** @var \Predis\Pipeline\Pipeline $pipe */
        $pipe = $this->redis->pipeline();
        // Remove from processing lists
        $pipe->lrem($this->processingKey($queue), 1, (string) $jobId);
        $pipe->zrem($this->processingZKey($queue), (string) $jobId);

        if ($delaySeconds > 0) {
            // Add to delayed ZSET with future timestamp
            $availableAt = $this->clock->timestamp() + $delaySeconds;
            $pipe->zadd($this->delayedKey($queue), [$jobId => $availableAt]);
        } else {
            // Immediate re-enqueue
            $pipe->lpush($this->pendingKey($queue), [(string) $jobId]);
        }
        $pipe->execute();
    }

    /**
     * Promote delayed jobs that are now due to the pending queue.
     *
     * @param string $queue Queue name
     * @param int $limit Maximum number of jobs to promote
     * @return int Number of jobs promoted
     */
    public function promoteDelayedJobs(string $queue, int $limit = 100): int
    {
        $now = $this->clock->timestamp();

        $result = $this->redis->eval(
            self::PROMOTE_DELAYED_LUA,
            2,
            $this->delayedKey($queue),
            $this->pendingKey($queue),
            (string) $now,
            (string) $limit
        );

        return is_int($result) ? $result : (is_numeric($result) ? (int) $result : 0);
    }

    /**
     * Recover stale processing jobs back to the pending queue.
     *
     * @param string $queue Queue name
     * @param int $ttlSeconds Time threshold - jobs processing longer than this are considered stale
     * @param int $limit Maximum number of jobs to recover
     * @return int Number of jobs recovered
     */
    public function recoverStaleProcessing(string $queue, int $ttlSeconds, int $limit = 100): int
    {
        if ($ttlSeconds < 1 || $limit < 1) {
            throw new \InvalidArgumentException('Stale recovery TTL and limit must be positive');
        }
        $this->repairUnscoredProcessing($queue, $limit);
        $staleThreshold = $this->clock->timestamp() - $ttlSeconds;

        $result = $this->redis->eval(
            self::RECOVER_STALE_LUA,
            3,
            $this->processingZKey($queue),
            $this->processingKey($queue),
            $this->pendingKey($queue),
            (string) $staleThreshold,
            (string) $limit
        );

        return is_int($result) ? $result : (is_numeric($result) ? (int) $result : 0);
    }

    /**
     * Get the count of pending jobs in a queue.
     *
     * @param string $queue Queue name
     * @return int Number of pending jobs
     */
    public function getPendingCount(string $queue): int
    {
        return $this->redis->llen($this->pendingKey($queue));
    }

    /**
     * Get the count of jobs currently being processed.
     *
     * @param string $queue Queue name
     * @return int Number of processing jobs
     */
    public function getProcessingCount(string $queue): int
    {
        return $this->redis->llen($this->processingKey($queue));
    }

    /**
     * Get the count of delayed jobs waiting for retry.
     *
     * @param string $queue Queue name
     * @return int Number of delayed jobs
     */
    public function getDelayedCount(string $queue): int
    {
        return $this->redis->zcard($this->delayedKey($queue));
    }

    /**
     * Clear all jobs from a queue (pending, processing, and delayed).
     *
     * @param string $queue Queue name
     */
    public function clear(string $queue): void
    {
        $this->redis->del([
            $this->pendingKey($queue),
            $this->processingKey($queue),
            $this->processingZKey($queue),
            $this->delayedKey($queue)
        ]);
    }

    /**
     * Enqueue multiple job IDs efficiently using Redis pipeline.
     * @param string $queue Queue name
     * @param int[] $jobIds Array of job identifiers
     */
    public function enqueueBatch(string $queue, array $jobIds): void
    {
        if ($jobIds === []) {
            return;
        }

        $key = $this->pendingKey($queue);
        $stringJobIds = array_map(fn($id) => (string) $id, $jobIds);
        $this->redis->lpush($key, $stringJobIds);
    }

    /**
     * Get all pending job IDs in the queue.
     *
     * @param string $queue Queue name
     * @return int[] Pending job IDs
     */
    public function getPendingIds(string $queue): array
    {
        $results = $this->redis->lrange($this->pendingKey($queue), 0, -1);
        return array_map(fn(string $id) => (int) $id, $results);
    }

    /**
     * Get all delayed job IDs in the queue.
     *
     * @param string $queue Queue name
     * @return int[] Delayed job IDs
     */
    public function getDelayedIds(string $queue): array
    {
        $results = $this->redis->zrange($this->delayedKey($queue), 0, -1);
        return array_map(fn(string $id) => (int) $id, $results);
    }

    private function pendingKey(string $queue): string
    {
        return sprintf('%s:queue:%s:pending', $this->prefix, $queue);
    }

    private function processingKey(string $queue): string
    {
        return sprintf('%s:queue:%s:processing', $this->prefix, $queue);
    }

    private function processingZKey(string $queue): string
    {
        return sprintf('%s:queue:%s:processing_z', $this->prefix, $queue);
    }

    private function delayedKey(string $queue): string
    {
        return sprintf('%s:queue:%s:delayed', $this->prefix, $queue);
    }

    private function validateJobId(int $jobId): void
    {
        if ($jobId < 1) {
            throw new \InvalidArgumentException('Job ID must be a positive integer');
        }
    }

    private function repairUnscoredProcessing(string $queue, int $limit): void
    {
        $cursor = $this->repairCursors[$queue] ?? 0;
        $ids = $this->redis->lrange($this->processingKey($queue), $cursor, $cursor + $limit - 1);
        $this->repairCursors[$queue] = count($ids) < $limit ? 0 : $cursor + count($ids);
        foreach ($ids as $id) {
            if (!$this->isValidRedisJobId($id)) {
                $this->discardMalformedProcessingNotification($queue, $id);
                continue;
            }
            if ($this->redis->zscore($this->processingZKey($queue), $id) === null) {
                $this->redis->zadd($this->processingZKey($queue), [(int) $id => $this->clock->timestamp()]);
            }
        }
    }

    private function discardMalformedProcessingNotification(string $queue, string $value): void
    {
        $this->redis->lrem($this->processingKey($queue), 0, $value);
        $this->redis->zrem($this->processingZKey($queue), $value);
    }

    private function isValidRedisJobId(string $value): bool
    {
        return preg_match('/^[1-9][0-9]*$/', $value) === 1
            && (strlen($value) < strlen((string) PHP_INT_MAX)
                || (strlen($value) === strlen((string) PHP_INT_MAX) && $value <= (string) PHP_INT_MAX));
    }
}
