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
use Oeltima\SimpleQueue\Contract\ClockInterface;
use Oeltima\SimpleQueue\SystemClock;

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
    SupportsBatchQueueReconciliation,
    SupportsQueueReconciliation,
    QueueStatsInterface,
    SupportsJobRemoval,
    SupportsProcessingHeartbeat,
    SupportsBoundedQueueMembership
{
    /** @var array<string, list<int>> Append-only buffers in insertion order */
    private array $pending = [];

    /** @var array<string, int> Consumed head indexes per queue */
    private array $pendingHead = [];

    /** @var array<string, int[]> */
    private array $processing = [];

    /** @var array<string, array<int, int>> Queue -> [jobId => timestamp] */
    private array $processingStartedAt = [];

    /** @var array<string, array<int, int>> Queue -> [jobId => availableAt timestamp] */
    private array $delayed = [];

    public function __construct(private readonly ClockInterface $clock = new SystemClock())
    {
    }

    public function isAvailable(): true
    {
        return true;
    }

    public function enqueue(string $queue, int $jobId): void
    {
        $this->validateJobId($jobId);
        if (!isset($this->pending[$queue])) {
            $this->pending[$queue] = [];
            $this->pendingHead[$queue] = 0;
        }
        // Amortized O(1) append; dequeue consumes from head.
        $this->pending[$queue][] = $jobId;
    }

    public function dequeue(string $queue, int $timeoutSeconds): ?int
    {
        if ($timeoutSeconds < 0) {
            throw new \InvalidArgumentException('Dequeue timeout must not be negative');
        }
        $head = $this->pendingHead[$queue] ?? 0;
        $buffer = $this->pending[$queue] ?? [];
        if ($head >= count($buffer)) {
            return null;
        }

        $jobId = $buffer[$head];
        $this->pendingHead[$queue] = $head + 1;
        $this->compactPending($queue);

        if (!isset($this->processing[$queue])) {
            $this->processing[$queue] = [];
        }
        $this->processing[$queue][] = $jobId;

        $this->processingStartedAt[$queue][$jobId] = $this->clock->timestamp();

        return $jobId;
    }

    /**
     * Compact the consumed head when it exceeds 1024 entries and half the buffer.
     *
     * @param string $queue Queue name
     */
    private function compactPending(string $queue): void
    {
        $head = $this->pendingHead[$queue] ?? 0;
        $buffer = $this->pending[$queue] ?? [];
        if ($head > 1024 && $head * 2 >= count($buffer)) {
            $this->pending[$queue] = array_slice($buffer, $head);
            $this->pendingHead[$queue] = 0;
        }
    }

    /**
     * Visible pending IDs in legacy newest-first order.
     *
     * @param string $queue Queue name
     * @return list<int> Pending IDs newest-first
     */
    private function visiblePending(string $queue): array
    {
        $head = $this->pendingHead[$queue] ?? 0;
        $slice = array_slice($this->pending[$queue] ?? [], $head);
        return array_reverse($slice);
    }

    public function ack(string $queue, int $jobId): void
    {
        $this->validateJobId($jobId);
        $this->removeFromProcessing($queue, $jobId);
        if (!in_array($jobId, $this->processing[$queue] ?? [], true)) {
            unset($this->processingStartedAt[$queue][$jobId]);
        }
    }

    public function remove(string $queue, int $jobId): void
    {
        $this->validateJobId($jobId);
        $head = $this->pendingHead[$queue] ?? 0;
        $buffer = $this->pending[$queue] ?? [];
        $visible = array_slice($buffer, $head);
        $filtered = array_values(array_filter($visible, static fn (int $id): bool => $id !== $jobId));
        $this->pending[$queue] = $filtered;
        $this->pendingHead[$queue] = 0;
        $this->processing[$queue] = array_values(array_filter(
            $this->processing[$queue] ?? [],
            static fn (int $id): bool => $id !== $jobId
        ));
        unset($this->delayed[$queue][$jobId], $this->processingStartedAt[$queue][$jobId]);
    }

    public function heartbeatProcessing(string $queue, int $jobId): void
    {
        $this->validateJobId($jobId);
        if (in_array($jobId, $this->processing[$queue] ?? [], true)) {
            $this->processingStartedAt[$queue][$jobId] = $this->clock->timestamp();
        }
    }

    public function hasPendingJob(string $queue, int $jobId, int $maxElements): bool
    {
        $this->validateJobId($jobId);
        if ($maxElements < 1) {
            throw new \InvalidArgumentException('Membership scan limit must be positive');
        }
        $head = $this->pendingHead[$queue] ?? 0;
        $buffer = $this->pending[$queue] ?? [];
        $inspected = 0;
        // Match Redis LPOS newest-first scan order without allocating a copy.
        for ($index = count($buffer) - 1; $index >= $head && $inspected < $maxElements; $index--, $inspected++) {
            if ($buffer[$index] === $jobId) {
                return true;
            }
        }
        return false;
    }

    public function hasDelayedJob(string $queue, int $jobId): bool
    {
        $this->validateJobId($jobId);
        return isset($this->delayed[$queue][$jobId]);
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
            $this->delayed[$queue][$jobId] = $this->clock->timestamp() + $delaySeconds;
        } else {
            $this->enqueue($queue, $jobId);
        }
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
        if (!isset($this->delayed[$queue])) {
            $this->delayed[$queue] = [];
        }
        $this->delayed[$queue][$jobId] = $availableAt;
    }

    /**
     * Add multiple jobs to the delayed notification structure in one batch.
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
        foreach ($jobIds as $jobId) {
            $this->validateJobId($jobId);
        }
        if (!isset($this->delayed[$queue])) {
            $this->delayed[$queue] = [];
        }
        foreach ($jobIds as $jobId) {
            $this->delayed[$queue][$jobId] = $availableAt;
        }
    }

    /**
     * Promote delayed jobs that are now due to the pending queue.
     *
     * Promotion selects by earliest availability; same-timestamp order is
     * deterministic but remains an undocumented implementation detail.
     *
     * @param string $queue Queue name
     * @return int Number of jobs promoted
     */
    public function promoteDelayedJobs(string $queue, int $limit = 100): int
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('Promotion limit must be positive');
        }
        if (!isset($this->delayed[$queue]) || $this->delayed[$queue] === []) {
            return 0;
        }

        $due = $this->dueNotifications($queue);
        usort($due, static function (array $a, array $b): int {
            $byTime = $a['at'] <=> $b['at'];
            return $byTime !== 0 ? $byTime : $a['id'] <=> $b['id'];
        });
        $selected = array_slice($due, 0, $limit);
        foreach ($selected as $item) {
            $this->enqueue($queue, $item['id']);
            unset($this->delayed[$queue][$item['id']]);
        }

        return count($selected);
    }

    /** @return list<array{id: int, at: int}> */
    private function dueNotifications(string $queue): array
    {
        $now = $this->clock->timestamp();
        $due = [];
        foreach ($this->delayed[$queue] as $jobId => $availableAt) {
            if ($availableAt <= $now) {
                $due[] = ['id' => $jobId, 'at' => $availableAt];
            }
        }
        return $due;
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

        $staleThreshold = $this->clock->timestamp() - $ttlSeconds;
        $recovered = 0;

        foreach ($this->processingStartedAt[$queue] as $jobId => $startedAt) {
            if ($recovered >= $limit) {
                break;
            }
            if ($startedAt > $staleThreshold) {
                continue;
            }
            $this->recoverProcessingNotification($queue, $jobId);
            $recovered++;
        }

        return $recovered;
    }

    private function recoverProcessingNotification(string $queue, int $jobId): void
    {
        $this->removeFromProcessing($queue, $jobId);
        if (in_array($jobId, $this->processing[$queue] ?? [], true)) {
            // Duplicate notifications share an ID-keyed score, so rebase the remaining copy.
            $this->processingStartedAt[$queue][$jobId] = $this->clock->timestamp();
        } else {
            unset($this->processingStartedAt[$queue][$jobId]);
        }
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
        return $this->visiblePending($queue);
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
        return $this->getPending($queue);
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
        $this->pendingHead = [];
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
     * The complete batch is validated before any queue is changed.
     *
     * @param string $queue Queue name
     * @param int[] $jobIds Array of job identifiers
     */
    public function enqueueBatch(string $queue, array $jobIds): void
    {
        foreach ($jobIds as $jobId) {
            $this->validateJobId($jobId);
        }
        foreach ($jobIds as $jobId) {
            $this->enqueue($queue, $jobId);
        }
    }

    /**
     * Reconcile a page of notifications atomically (in-memory parity).
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
        $this->validateReconciliationInput($availableAtByJobId, $now, $pendingScanLimit);

        $pending = $this->boundedPendingSet($queue, $pendingScanLimit);
        $delayed = $this->delayed[$queue] ?? [];
        $present = [];
        foreach ($availableAtByJobId as $jobId => $availableAt) {
            if ($this->notificationIsPresent($jobId, $pending, $delayed)) {
                $present[] = $jobId;
                continue;
            }
            $this->restoreNotification($queue, $jobId, $availableAt, $now);
        }
        return $present;
    }

    /** @param array<int, int> $availableAtByJobId */
    private function validateReconciliationInput(array $availableAtByJobId, int $now, int $pendingScanLimit): void
    {
        if ($now <= 0) {
            throw new \InvalidArgumentException('Reconciliation current timestamp must be positive');
        }
        if ($pendingScanLimit < 1) {
            throw new \InvalidArgumentException('Pending scan limit must be positive');
        }
        foreach ($availableAtByJobId as $jobId => $availableAt) {
            $this->validateReconciliationPair($jobId, $availableAt);
        }
    }

    private function validateReconciliationPair(mixed $jobId, mixed $availableAt): void
    {
        if (!is_int($jobId)) {
            throw new \InvalidArgumentException('Reconciliation IDs must be positive integers');
        }
        if ($jobId < 1) {
            throw new \InvalidArgumentException('Reconciliation IDs must be positive integers');
        }
        if (!is_int($availableAt)) {
            throw new \InvalidArgumentException('Reconciliation timestamps must be positive integers');
        }
        if ($availableAt <= 0) {
            throw new \InvalidArgumentException('Reconciliation timestamps must be positive integers');
        }
    }

    /**
     * @param array<int, true> $pending
     * @param array<int, int> $delayed
     */
    private function notificationIsPresent(int $jobId, array $pending, array $delayed): bool
    {
        if (isset($pending[$jobId])) {
            return true;
        }
        return isset($delayed[$jobId]);
    }

    private function restoreNotification(string $queue, int $jobId, int $availableAt, int $now): void
    {
        if ($availableAt <= $now) {
            $this->enqueue($queue, $jobId);
            return;
        }
        $this->delayed[$queue] ??= [];
        $this->delayed[$queue][$jobId] = $availableAt;
    }

    /**
     * Build the bounded newest-first membership set once per reconciliation page.
     *
     * @return array<int, true> Job IDs visible within the scan bound
     */
    private function boundedPendingSet(string $queue, int $maxElements): array
    {
        $head = $this->pendingHead[$queue] ?? 0;
        $buffer = $this->pending[$queue] ?? [];
        $set = [];
        $inspected = 0;
        for ($index = count($buffer) - 1; $index >= $head && $inspected < $maxElements; $index--, $inspected++) {
            $set[$buffer[$index]] = true;
        }
        return $set;
    }

    /**
     * Get the count of pending jobs in a queue.
     *
     * @param string $queue Queue name
     * @return int Number of pending jobs
     */
    public function getPendingCount(string $queue): int
    {
        return count($this->getPending($queue));
    }

    /**
     * Get the count of jobs currently being processed.
     *
     * @param string $queue Queue name
     * @return int Number of processing jobs
     */
    public function getProcessingCount(string $queue): int
    {
        return count($this->getProcessing($queue));
    }

    /**
     * Get the count of delayed jobs waiting for retry.
     *
     * @param string $queue Queue name
     * @return int Number of delayed jobs
     */
    public function getDelayedCount(string $queue): int
    {
        return count($this->getDelayed($queue));
    }

    private function removeFromProcessing(string $queue, int $jobId): void
    {
        if (!isset($this->processing[$queue])) {
            return;
        }

        $key = array_search($jobId, $this->processing[$queue], true);
        if ($key === false) {
            return;
        }
        unset($this->processing[$queue][$key]);
        $this->processing[$queue] = array_values($this->processing[$queue]);
    }
}
