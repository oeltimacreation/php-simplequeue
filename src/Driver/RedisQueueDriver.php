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
        $result = $this->redis->brpoplpush(
            $this->pendingKey($queue),
            $this->processingKey($queue),
            $timeoutSeconds
        );

        if (empty($result)) {
            return null;
        }

        return (int) $result;
    }

    public function ack(string $queue, int $jobId): void
    {
        $this->redis->lrem($this->processingKey($queue), 1, (string) $jobId);
    }

    public function nack(string $queue, int $jobId): void
    {
        $this->redis->lrem($this->processingKey($queue), 1, (string) $jobId);
        $this->enqueue($queue, $jobId);
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
     * Clear all jobs from a queue (both pending and processing).
     *
     * @param string $queue Queue name
     */
    public function clear(string $queue): void
    {
        $this->redis->del([$this->pendingKey($queue), $this->processingKey($queue)]);
    }

    private function pendingKey(string $queue): string
    {
        return sprintf('%s:queue:%s:pending', $this->prefix, $queue);
    }

    private function processingKey(string $queue): string
    {
        return sprintf('%s:queue:%s:processing', $this->prefix, $queue);
    }
}
