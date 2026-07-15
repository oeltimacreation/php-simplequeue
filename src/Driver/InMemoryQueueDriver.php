<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Driver;

use Oeltima\SimpleQueue\Contract\QueueDriverInterface;
use Oeltima\SimpleQueue\Contract\SupportsDelayedJobs;
use Oeltima\SimpleQueue\Contract\SupportsStaleRecovery;
use Oeltima\SimpleQueue\Contract\SupportsBatchEnqueue;
use Oeltima\SimpleQueue\Contract\SupportsQueueReconciliation;
use Oeltima\SimpleQueue\Contract\QueueStatsInterface;
use Oeltima\SimpleQueue\Contract\SupportsJobRemoval;

/**
 * In-memory queue driver for testing purposes.
 *
 * This driver stores jobs in memory and is useful for unit testing.
 * Jobs are lost when the process terminates.
 */
final class InMemoryQueueDriver implements
    QueueDriverInterface,
    SupportsDelayedJobs,
    SupportsStaleRecovery,
    SupportsBatchEnqueue,
    SupportsQueueReconciliation,
    QueueStatsInterface,
    SupportsJobRemoval
{
    /** @var array<string, int[]> */
    private array $pending = [];

    /** @var array<string, int[]> */
    private array $processing = [];

    /** @var array<string, array<int, int>> Queue -> [jobId => timestamp] */
    private array $processingStartedAt = [];

    /** @var array<string, array<int, int>> Queue -> [jobId => availableAt timestamp] */
    private array $delayed = [];

    public function isAvailable(): true
    {
        return true;
    }

    public function enqueue(string $queue, int $jobId): void
    {
        $this->validateJobId($jobId);
        if (!isset($this->pending[$queue])) {
            $this->pending[$queue] = [];
        }
        array_unshift($this->pending[$queue], $jobId);
    }

    public function dequeue(string $queue, int $timeoutSeconds): ?int
    {
        if ($timeoutSeconds < 0) {
            throw new \InvalidArgumentException('Dequeue timeout must not be negative');
        }
        if (!isset($this->pending[$queue]) || $this->pending[$queue] === []) {
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
        $this->validateJobId($jobId);
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

    public function remove(string $queue, int $jobId): void
    {
        $this->validateJobId($jobId);
        $this->pending[$queue] = array_values(array_filter(
            $this->pending[$queue] ?? [],
            static fn (int $id): bool => $id !== $jobId
        ));
        $this->processing[$queue] = array_values(array_filter(
            $this->processing[$queue] ?? [],
            static fn (int $id): bool => $id !== $jobId
        ));
        unset($this->delayed[$queue][$jobId], $this->processingStartedAt[$queue][$jobId]);
    }

    public function nack(string $queue, int $jobId, int $delaySeconds = 0): void
    {
        $this->validateJobId($jobId);
        if ($delaySeconds < 0) {
            throw new \InvalidArgumentException('Retry delay must not be negative');
        }
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
    public function promoteDelayedJobs(string $queue, int $limit = 100): int
    {
        if (!isset($this->delayed[$queue]) || $this->delayed[$queue] === []) {
            return 0;
        }

        $now = time();
        $promoted = 0;

        foreach ($this->delayed[$queue] as $jobId => $availableAt) {
            if ($promoted >= $limit) {
                break;
            }
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
     * @param int $limit Maximum number of jobs to recover
     * @return int Number of jobs recovered
     */
    public function recoverStaleProcessing(string $queue, int $ttlSeconds, int $limit = 100): int
    {
        if ($ttlSeconds < 1 || $limit < 1) {
            throw new \InvalidArgumentException('Stale recovery TTL and limit must be positive');
        }
        if (!isset($this->processingStartedAt[$queue]) || $this->processingStartedAt[$queue] === []) {
            return 0;
        }

        $staleThreshold = time() - $ttlSeconds;
        $recovered = 0;

        foreach ($this->processingStartedAt[$queue] as $jobId => $startedAt) {
            if ($recovered >= $limit) {
                break;
            }
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
     * Get all pending job IDs in the queue.
     *
     * @param string $queue Queue name
     * @return int[] Pending job IDs
     */
    public function getPendingIds(string $queue): array
    {
        return $this->pending[$queue] ?? [];
    }

    /**
     * Get all delayed job IDs in the queue.
     *
     * @param string $queue Queue name
     * @return int[] Delayed job IDs
     */
    public function getDelayedIds(string $queue): array
    {
        return isset($this->delayed[$queue]) ? array_keys($this->delayed[$queue]) : [];
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

    private function validateJobId(int $jobId): void
    {
        if ($jobId < 1) {
            throw new \InvalidArgumentException('Job ID must be a positive integer');
        }
    }

    /**
     * Enqueue multiple job IDs efficiently.
     *
     * @param string $queue Queue name
     * @param int[] $jobIds Array of job identifiers
     */
    public function enqueueBatch(string $queue, array $jobIds): void
    {
        if ($jobIds === []) {
            return;
        }

        if (!isset($this->pending[$queue])) {
            $this->pending[$queue] = [];
        }

        foreach ($jobIds as $jobId) {
            array_unshift($this->pending[$queue], $jobId);
        }
    }

    /**
     * Get the count of pending jobs in a queue.
     *
     * @param string $queue Queue name
     * @return int Number of pending jobs
     */
    public function getPendingCount(string $queue): int
    {
        return isset($this->pending[$queue]) ? count($this->pending[$queue]) : 0;
    }

    /**
     * Get the count of jobs currently being processed.
     *
     * @param string $queue Queue name
     * @return int Number of processing jobs
     */
    public function getProcessingCount(string $queue): int
    {
        return isset($this->processing[$queue]) ? count($this->processing[$queue]) : 0;
    }

    /**
     * Get the count of delayed jobs waiting for retry.
     *
     * @param string $queue Queue name
     * @return int Number of delayed jobs
     */
    public function getDelayedCount(string $queue): int
    {
        return isset($this->delayed[$queue]) ? count($this->delayed[$queue]) : 0;
    }
}
