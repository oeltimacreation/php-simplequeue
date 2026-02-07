<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Driver;

use Oeltima\SimpleQueue\Contract\QueueDriverInterface;
use Predis\ClientInterface;

/**
 * Redis-based queue driver using list operations.
 *
 * This driver uses Redis lists with BRPOPLPUSH for reliable
 * queue processing with at-least-once delivery guarantees.
 */
final class RedisQueueDriver implements QueueDriverInterface
{
    private ClientInterface $redis;
    private string $prefix;

    /**
     * @param ClientInterface $redis Predis client instance
     * @param string $prefix Key prefix for all queue keys
     */
    public function __construct(ClientInterface $redis, string $prefix = 'simplequeue')
    {
        $this->redis = $redis;
        $this->prefix = $prefix;
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

    public function enqueue(string $queue, int $jobId): void
    {
        $this->redis->lpush($this->pendingKey($queue), [(string) $jobId]);
    }

    public function dequeue(string $queue, int $timeoutSeconds): ?int
    {
        if ($timeoutSeconds <= 0) {
            // Non-blocking: use RPOPLPUSH instead of BRPOPLPUSH
            $result = $this->redis->rpoplpush(
                $this->pendingKey($queue),
                $this->processingKey($queue)
            );
        } else {
            // Blocking with timeout
            $result = $this->redis->brpoplpush(
                $this->pendingKey($queue),
                $this->processingKey($queue),
                $timeoutSeconds
            );
        }

        if (empty($result)) {
            return null;
        }

        $jobId = (int) $result;

        // Track processing start time in ZSET
        $this->redis->zadd($this->processingZKey($queue), [$jobId => time()]);

        return $jobId;
    }

    public function ack(string $queue, int $jobId): void
    {
        $this->redis->lrem($this->processingKey($queue), 1, (string) $jobId);
        $this->redis->zrem($this->processingZKey($queue), (string) $jobId);
    }

    public function nack(string $queue, int $jobId, int $delaySeconds = 0): void
    {
        // Remove from processing lists
        $this->redis->lrem($this->processingKey($queue), 1, (string) $jobId);
        $this->redis->zrem($this->processingZKey($queue), (string) $jobId);

        if ($delaySeconds > 0) {
            // Add to delayed ZSET with future timestamp
            $availableAt = time() + $delaySeconds;
            $this->redis->zadd($this->delayedKey($queue), [$jobId => $availableAt]);
        } else {
            // Immediate re-enqueue
            $this->enqueue($queue, $jobId);
        }
    }

    /**
     * Promote delayed jobs that are now due to the pending queue.
     *
     * @param string $queue Queue name
     * @return int Number of jobs promoted
     */
    public function promoteDelayedJobs(string $queue): int
    {
        $now = time();
        $dueJobs = $this->redis->zrangebyscore($this->delayedKey($queue), '-inf', (string) $now);

        if (empty($dueJobs)) {
            return 0;
        }

        $pipe = $this->redis->pipeline();
        foreach ($dueJobs as $jobId) {
            $pipe->lpush($this->pendingKey($queue), (string) $jobId);
        }
        $pipe->zremrangebyscore($this->delayedKey($queue), '-inf', (string) $now);
        $pipe->execute();

        return count($dueJobs);
    }

    /**
     * Recover stale processing jobs back to the pending queue.
     *
     * @param string $queue Queue name
     * @param int $ttlSeconds Time threshold - jobs processing longer than this are considered stale
     * @return int Number of jobs recovered
     */
    public function recoverStaleProcessing(string $queue, int $ttlSeconds): int
    {
        $staleThreshold = time() - $ttlSeconds;
        $staleJobs = $this->redis->zrangebyscore(
            $this->processingZKey($queue),
            '-inf',
            (string) $staleThreshold
        );

        if (empty($staleJobs)) {
            return 0;
        }

        $pipe = $this->redis->pipeline();
        foreach ($staleJobs as $jobId) {
            $pipe->lrem($this->processingKey($queue), 1, (string) $jobId);
            $pipe->lpush($this->pendingKey($queue), (string) $jobId);
        }
        $pipe->zremrangebyscore($this->processingZKey($queue), '-inf', (string) $staleThreshold);
        $pipe->execute();

        return count($staleJobs);
    }

    /**
     * Get the count of pending jobs in a queue.
     *
     * @param string $queue Queue name
     * @return int Number of pending jobs
     */
    public function getPendingCount(string $queue): int
    {
        return (int) $this->redis->llen($this->pendingKey($queue));
    }

    /**
     * Get the count of jobs currently being processed.
     *
     * @param string $queue Queue name
     * @return int Number of processing jobs
     */
    public function getProcessingCount(string $queue): int
    {
        return (int) $this->redis->llen($this->processingKey($queue));
    }

    /**
     * Get the count of delayed jobs waiting for retry.
     *
     * @param string $queue Queue name
     * @return int Number of delayed jobs
     */
    public function getDelayedCount(string $queue): int
    {
        return (int) $this->redis->zcard($this->delayedKey($queue));
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
     *
     * @param string $queue Queue name
     * @param int[] $jobIds Array of job identifiers
     */
    public function enqueueBatch(string $queue, array $jobIds): void
    {
        if ($jobIds === []) {
            return;
        }

        $key = $this->pendingKey($queue);
        $pipe = $this->redis->pipeline();
        foreach ($jobIds as $jobId) {
            $pipe->lpush($key, [(string) $jobId]);
        }
        $pipe->execute();
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
}
