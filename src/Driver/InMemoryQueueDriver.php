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

    /** @var array<string, array<int, int>> Queue -> [jobId => timestamp] */
    private array $processingStartedAt = [];

    /** @var array<string, array<int, int>> Queue -> [jobId => availableAt timestamp] */
    private array $delayed = [];

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

        $this->processingStartedAt[$queue][$jobId] = time();

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

        unset($this->processingStartedAt[$queue][$jobId]);
    }

    public function nack(string $queue, int $jobId, int $delaySeconds = 0): void
    {
        $this->ack($queue, $jobId);
        if ($delaySeconds > 0) {
            if (!isset($this->delayed[$queue])) {
                $this->delayed[$queue] = [];
            }
            $this->delayed[$queue][$jobId] = time() + $delaySeconds;
        } else {
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
        if (!isset($this->delayed[$queue]) || empty($this->delayed[$queue])) {
            return 0;
        }

        $now = time();
        $promoted = 0;

        foreach ($this->delayed[$queue] as $jobId => $availableAt) {
            if ($availableAt <= $now) {
                $this->enqueue($queue, $jobId);
                unset($this->delayed[$queue][$jobId]);
                $promoted++;
            }
        }

        return $promoted;
    }

    /**
     * Recover stale processing jobs back to the pending queue.
     *
     * @param string $queue Queue name
     * @param int $ttlSeconds Time threshold
     * @return int Number of jobs recovered
     */
    public function recoverStaleProcessing(string $queue, int $ttlSeconds): int
    {
        if (!isset($this->processingStartedAt[$queue]) || empty($this->processingStartedAt[$queue])) {
            return 0;
        }

        $staleThreshold = time() - $ttlSeconds;
        $recovered = 0;

        foreach ($this->processingStartedAt[$queue] as $jobId => $startedAt) {
            if ($startedAt <= $staleThreshold) {
                if (isset($this->processing[$queue])) {
                    $key = array_search($jobId, $this->processing[$queue], true);
                    if ($key !== false) {
                        unset($this->processing[$queue][$key]);
                        $this->processing[$queue] = array_values($this->processing[$queue]);
                    }
                }
                unset($this->processingStartedAt[$queue][$jobId]);
                $this->enqueue($queue, $jobId);
                $recovered++;
            }
        }

        return $recovered;
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
     * Get delayed job IDs for a queue (for testing).
     *
     * @param string $queue Queue name
     * @return array<int, int> [jobId => availableAt timestamp]
     */
    public function getDelayed(string $queue): array
    {
        return $this->delayed[$queue] ?? [];
    }

    /**
     * Clear all queues.
     */
    public function clear(): void
    {
        $this->pending = [];
        $this->processing = [];
        $this->processingStartedAt = [];
        $this->delayed = [];
    }
}
