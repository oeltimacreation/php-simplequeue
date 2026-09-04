<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Driver;

use Oeltima\SimpleQueue\Contract\QueueDriverInterface;
use Oeltima\SimpleQueue\Contract\SupportsBatchEnqueue;
use Oeltima\SimpleQueue\Contract\SupportsBatchQueueReconciliation;
use Oeltima\SimpleQueue\Contract\SupportsBoundedQueueMembership;
use Oeltima\SimpleQueue\Contract\SupportsDelayedJobs;
use Oeltima\SimpleQueue\Contract\SupportsJobRemoval;
use Oeltima\SimpleQueue\Contract\SupportsProcessingHeartbeat;
use Oeltima\SimpleQueue\Contract\SupportsQueueReconciliation;
use Oeltima\SimpleQueue\Contract\QueueStatsInterface;
use Oeltima\SimpleQueue\Contract\SupportsStaleRecovery;
use Oeltima\SimpleQueue\Contract\SupportsTimeoutValidation;
use Oeltima\SimpleQueue\Contract\ClockInterface;
use Oeltima\SimpleQueue\Exception\QueueException;
use Oeltima\SimpleQueue\Internal\PositiveJobId;
use Oeltima\SimpleQueue\Internal\RedisProcessingRepair;
use Oeltima\SimpleQueue\Internal\RedisResponseNormalizer;
use Oeltima\SimpleQueue\Internal\RedisScriptRunner;
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
    SupportsBatchQueueReconciliation,
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
local chunkSize = 1000
for i = 1, #dueJobs, chunkSize do
    local j = math.min(i + chunkSize - 1, #dueJobs)
    redis.call('LPUSH', pendingKey, unpack(dueJobs, i, j))
    redis.call('ZREM', delayedKey, unpack(dueJobs, i, j))
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
    local chunkSize = 1000
    for i = 1, #staleJobs, chunkSize do
        local j = math.min(i + chunkSize - 1, #staleJobs)
        for k = i, j do
            redis.call('LREM', processingKey, 1, staleJobs[k])
        end
        redis.call('LPUSH', pendingKey, unpack(staleJobs, i, j))
        redis.call('ZREM', processingZKey, unpack(staleJobs, i, j))
    end
end
return #staleJobs
LUA;

    private const RECONCILE_BATCH_LUA = <<<'LUA'
local pendingKey = KEYS[1]
local delayedKey = KEYS[2]
local now = tonumber(ARGV[1])
local pendingScanLimit = tonumber(ARGV[2])
local count = tonumber(ARGV[3])
local present = {}
for i = 1, count do
    local jobId = ARGV[3 + i]
    local availableAt = tonumber(ARGV[3 + count + i])
    local found = false
    if redis.call('LPOS', pendingKey, jobId, 'MAXLEN', pendingScanLimit) then
        found = true
    elseif redis.call('ZSCORE', delayedKey, jobId) then
        found = true
    end
    if found then
        present[#present + 1] = jobId
    else
        if availableAt <= now then
            redis.call('LPUSH', pendingKey, jobId)
        else
            redis.call('ZADD', delayedKey, availableAt, jobId)
        end
    end
end
return present
LUA;

    /** @var array<string, int> */
    private array $repairCursors = [];

    private readonly RedisScriptRunner $scripts;

    /**
     * @param ClientInterface $redis Predis client instance
     * @param string $prefix Key prefix for all queue keys
     */
    public function __construct(
        #[\SensitiveParameter] private readonly ClientInterface $redis,
        private readonly string $prefix = 'simplequeue',
        private readonly ClockInterface $clock = new SystemClock()
    ) {
        $this->scripts = new RedisScriptRunner($this->redis);
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
            $readWriteTimeout = $this->readWriteTimeout();
            if ($readWriteTimeout === null || $readWriteTimeout <= 0 || $pollTimeout < $readWriteTimeout) {
                return;
            }

            throw new \InvalidArgumentException(sprintf(
                'Unsafe timeout configuration: poll_timeout (%ds) must be strictly less than ' .
                'Predis read_write_timeout (%ds) to prevent connection dropped errors.',
                $pollTimeout,
                $readWriteTimeout
            ));
        } catch (\InvalidArgumentException $exception) {
            throw $exception;
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
        $result = $this->dequeueResponse($queue, $timeoutSeconds);
        $jobId = RedisResponseNormalizer::dequeuedJobId(
            $queue,
            $result,
            $this->discardMalformedProcessingNotification(...)
        );
        if ($jobId !== null && $timeoutSeconds > 0) {
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
     * Add a job to the delayed notification structure.
     *
     * @param string $queue Queue name
     * @param int $jobId Job identifier
     * @param int $availableAt Unix timestamp when the job becomes available
     */
    public function enqueueDelayed(string $queue, int $jobId, int $availableAt): void
    {
        $this->validateJobId($jobId);
        if ($availableAt <= 0) {
            throw new \InvalidArgumentException('Delayed availability timestamp must be positive');
        }
        $this->redis->zadd($this->delayedKey($queue), [$jobId => $availableAt]);
    }

    /**
     * Add multiple jobs to the delayed notification structure in one ZADD.
     *
     * @param string $queue Queue name
     * @param int[] $jobIds Job identifiers
     * @param int $availableAt Unix timestamp when all jobs become available
     */
    public function enqueueDelayedBatch(string $queue, array $jobIds, int $availableAt): void
    {
        if ($jobIds === []) {
            return;
        }
        if ($availableAt <= 0) {
            throw new \InvalidArgumentException('Delayed availability timestamp must be positive');
        }
        $members = [];
        foreach ($jobIds as $jobId) {
            $this->validateJobId($jobId);
            $members[$jobId] = $availableAt;
        }
        $this->redis->zadd($this->delayedKey($queue), $members);
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
        if ($limit < 1) {
            throw new \InvalidArgumentException('Promotion limit must be positive');
        }
        $now = $this->clock->timestamp();

        $result = $this->scripts->run(
            self::PROMOTE_DELAYED_LUA,
            [
                $this->delayedKey($queue),
                $this->pendingKey($queue),
            ],
            [(string) $now, (string) $limit]
        );

        return RedisResponseNormalizer::integer($result);
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

        $result = $this->scripts->run(
            self::RECOVER_STALE_LUA,
            [
                $this->processingZKey($queue),
                $this->processingKey($queue),
                $this->pendingKey($queue),
            ],
            [(string) $staleThreshold, (string) $limit]
        );

        return RedisResponseNormalizer::integer($result);
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
        foreach ($jobIds as $jobId) {
            $this->validateJobId($jobId);
        }

        $key = $this->pendingKey($queue);
        $stringJobIds = array_map(fn($id) => (string) $id, $jobIds);
        $this->redis->lpush($key, $stringJobIds);
    }

    /**
     * Reconcile a page of notifications in one direct EVAL roundtrip.
     *
     * Inputs are validated in PHP; the script checks bounded pending LPOS
     * and exact delayed ZSCORE, restores missing due/future IDs, and returns
     * IDs already present in input order.
     *
     * @param string $queue Queue name
     * @param array<int, int> $availableAtByJobId Job ID => absolute Unix timestamp
     * @param int $now Current absolute Unix timestamp
     * @param int $pendingScanLimit Maximum pending-list elements inspected per ID
     * @return list<int> IDs already present in pending or delayed notifications
     */
    public function reconcileNotifications(
        string $queue,
        array $availableAtByJobId,
        int $now,
        int $pendingScanLimit
    ): array {
        if ($availableAtByJobId === []) {
            return [];
        }
        if ($pendingScanLimit < 1) {
            throw new \InvalidArgumentException('Pending scan limit must be positive');
        }
        $ids = [];
        $timestamps = [];
        foreach ($availableAtByJobId as $jobId => $availableAt) {
            if (!is_int($jobId) || $jobId < 1) {
                throw new \InvalidArgumentException('Reconciliation job IDs must be positive integers');
            }
            if (!is_int($availableAt) || $availableAt <= 0) {
                throw new \InvalidArgumentException('Reconciliation timestamps must be positive integers');
            }
            $ids[] = (string) $jobId;
            $timestamps[] = (string) $availableAt;
        }
        // Direct EVAL (not EVALSHA) so a cold script cache cannot add a NOSCRIPT retry.
        $result = $this->redis->eval(
            self::RECONCILE_BATCH_LUA,
            2,
            $this->pendingKey($queue),
            $this->delayedKey($queue),
            (string) $now,
            (string) $pendingScanLimit,
            (string) count($ids),
            ...[...$ids, ...$timestamps]
        );
        if ($result === null || $result === false) {
            return [];
        }
        $raw = is_array($result) ? $result : [$result];
        $present = [];
        foreach ($raw as $value) {
            if (!is_scalar($value)) {
                throw new QueueException('Redis returned a malformed reconciliation ID');
            }
            $str = (string) $value;
            if (preg_match('/^(0|[1-9][0-9]*)$/', $str) !== 1) {
                throw new QueueException('Redis returned a malformed reconciliation ID');
            }
            $present[] = (int) $str;
        }
        // Return unique already-present IDs in input order.
        $order = array_flip(array_map('strval', array_keys($availableAtByJobId)));
        usort($present, static function (int $a, int $b) use ($order): int {
            $rankA = $order[(string) $a] ?? PHP_INT_MAX;
            $rankB = $order[(string) $b] ?? PHP_INT_MAX;
            return $rankA <=> $rankB;
        });
        return array_values(array_unique($present));
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
        return $this->queueKey($queue, 'pending');
    }

    private function processingKey(string $queue): string
    {
        return $this->queueKey($queue, 'processing');
    }

    private function processingZKey(string $queue): string
    {
        return $this->queueKey($queue, 'processing_z');
    }

    private function delayedKey(string $queue): string
    {
        return $this->queueKey($queue, 'delayed');
    }

    private function queueKey(string $queue, string $suffix): string
    {
        return sprintf('%s:queue:%s:%s', $this->prefix, $queue, $suffix);
    }

    private function validateJobId(int $jobId): void
    {
        PositiveJobId::fromInt($jobId);
    }

    private function readWriteTimeout(): ?float
    {
        $connection = $this->redis->getConnection();
        // @phpstan-ignore-next-line Optional/custom Predis connections are accepted at this boundary.
        if (!method_exists($connection, 'getParameters')) {
            return null;
        }

        $parameters = $connection->getParameters();
        return $parameters->read_write_timeout ?? null;
    }

    private function dequeueResponse(string $queue, int $timeoutSeconds): mixed
    {
        if ($timeoutSeconds <= 0) {
            return $this->scripts->run(
                self::DEQUEUE_LUA,
                [
                    $this->pendingKey($queue),
                    $this->processingKey($queue),
                    $this->processingZKey($queue),
                ],
                [(string) $this->clock->timestamp()]
            );
        }

        return $this->redis->blmove(
            $this->pendingKey($queue),
            $this->processingKey($queue),
            'RIGHT',
            'LEFT',
            $timeoutSeconds
        );
    }

    private function repairUnscoredProcessing(string $queue, int $limit): void
    {
        $cursor = $this->repairCursors[$queue] ?? 0;
        $ids = $this->redis->lrange($this->processingKey($queue), $cursor, $cursor + $limit - 1);
        $this->repairCursors[$queue] = count($ids) < $limit ? 0 : $cursor + count($ids);
        RedisProcessingRepair::repair(
            $this->redis,
            $this->clock,
            [
                'processing' => $this->processingKey($queue),
                'scores' => $this->processingZKey($queue),
            ],
            array_values($ids)
        );
    }

    private function discardMalformedProcessingNotification(string $queue, string $value): void
    {
        $this->redis->lrem($this->processingKey($queue), 0, $value);
        $this->redis->zrem($this->processingZKey($queue), $value);
    }
}
