<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Driver;

use Oeltima\SimpleQueue\Contract\QueueDriverInterface;

/**
 * In-memory queue driver for testing purposes.
 *
 * This driver stores jobs in memory and is useful for unit testing.
 * Jobs are lost when the process terminates.
 */
final class InMemoryQueueDriver implements QueueDriverInterface
{
    /** @var array<string, int[]> */
    private array $pending = [];

    /** @var array<string, int[]> */
    private array $processing = [];

    public function isAvailable(): bool
    {
        return true;
    }

    public function enqueue(string $queue, int $jobId): void
    {
        if (!isset($this->pending[$queue])) {
            $this->pending[$queue] = [];
        }
        array_unshift($this->pending[$queue], $jobId);
    }

    public function dequeue(string $queue, int $timeoutSeconds): ?int
    {
        if (!isset($this->pending[$queue]) || empty($this->pending[$queue])) {
            return null;
        }

        $jobId = array_pop($this->pending[$queue]);

        if (!isset($this->processing[$queue])) {
            $this->processing[$queue] = [];
        }
        $this->processing[$queue][] = $jobId;

        return $jobId;
    }

    public function ack(string $queue, int $jobId): void
    {
        if (!isset($this->processing[$queue])) {
            return;
        }

        $key = array_search($jobId, $this->processing[$queue], true);
        if ($key !== false) {
            unset($this->processing[$queue][$key]);
            $this->processing[$queue] = array_values($this->processing[$queue]);
        }
    }

    public function nack(string $queue, int $jobId, int $delaySeconds = 0): void
    {
        $this->ack($queue, $jobId);
        // In-memory driver ignores delay for simplicity (testing purposes)
        $this->enqueue($queue, $jobId);
    }

    /**
     * Get all pending job IDs for a queue.
     *
     * @param string $queue Queue name
     * @return int[]
     */
    public function getPending(string $queue): array
    {
        return $this->pending[$queue] ?? [];
    }

    /**
     * Get all processing job IDs for a queue.
     *
     * @param string $queue Queue name
     * @return int[]
     */
    public function getProcessing(string $queue): array
    {
        return $this->processing[$queue] ?? [];
    }

    /**
     * Clear all queues.
     */
    public function clear(): void
    {
        $this->pending = [];
        $this->processing = [];
    }
}
