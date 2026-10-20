<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue;

use Oeltima\SimpleQueue\Contract\ClockInterface;
use Oeltima\SimpleQueue\Contract\JobStorageInterface;
use Oeltima\SimpleQueue\Contract\QueueDriverInterface;
use Oeltima\SimpleQueue\Contract\SupportsBoundedQueueMembership;
use Oeltima\SimpleQueue\Contract\SupportsDelayedJobs;
use Oeltima\SimpleQueue\Contract\SupportsPendingJobCursor;
use Oeltima\SimpleQueue\Contract\SupportsPendingNotificationCursor;
use Oeltima\SimpleQueue\Contract\SupportsQueueScopedStaleRecovery;
use Oeltima\SimpleQueue\Contract\SupportsStaleRecovery;
use Oeltima\SimpleQueue\Internal\JobStorageRules;

/** Run maintenance independently of handlers, with no implicit thread or global cursor. */
final readonly class QueueMaintenance
{
    /**
     * @param JobStorageInterface $storage Durable storage
     * @param QueueDriverInterface $driver Queue notifications
     * @param ClockInterface $clock Maintenance clock
     */
    public function __construct(
        private JobStorageInterface $storage,
        private QueueDriverInterface $driver,
        private ClockInterface $clock = new SystemClock()
    ) {
    }

    /**
     * @param string $queue Queue name
     * @param MaintenanceOptions $options Work bounds
     * @param int|null $cursor Exclusive reconciliation cursor from the previous pass
     * @return MaintenanceResult Completed/deferred phases and next cursor
     */
    public function run(
        string $queue,
        MaintenanceOptions $options = new MaintenanceOptions(),
        ?int $cursor = null
    ): MaintenanceResult {
        JobStorageRules::validateQueueOrType($queue, 'Queue');
        if (!$this->storage instanceof SupportsQueueScopedStaleRecovery) {
            throw new \LogicException('Standalone bounded maintenance requires queue-scoped stale recovery');
        }
        $reconcileOptions = new ReconcileOptions(
            $cursor,
            $options->pageSize,
            $options->membershipScanLimit,
            $options->maxDurationSeconds
        );
        $started = $this->clock->monotonic();
        $promoted = $this->promote($queue, $options->promoteLimit);
        $recoveryRan = $this->clock->monotonic() - $started < $options->maxDurationSeconds;
        $recovered = $recoveryRan ? $this->recover($queue, $options->stuckJobTtl, $options->recoveryLimit) : 0;
        $remaining = $options->maxDurationSeconds - ($this->clock->monotonic() - $started);
        $result = $remaining > 0 ? $this->reconcile($queue, new ReconcileOptions(
            $reconcileOptions->cursor,
            $reconcileOptions->pageSize,
            $reconcileOptions->membershipScanLimit,
            $remaining
        )) : null;
        return new MaintenanceResult(
            $promoted,
            $recovered,
            $result,
            $result === null ? $cursor : $result->nextCursor,
            $this->clock->monotonic() - $started,
            $recoveryRan,
            $result !== null
        );
    }

    /**
     * @param string $queue Queue name
     * @param int $limit Promotion bound
     * @return int Promoted notifications
     */
    public function promote(string $queue, int $limit): int
    {
        JobStorageRules::validateQueueOrType($queue, 'Queue');
        JobStorageRules::validatePositiveLimit($limit, 'Promotion limit');
        return $this->driver instanceof SupportsDelayedJobs ? $this->driver->promoteDelayedJobs($queue, $limit) : 0;
    }

    /**
     * @param string $queue Queue name
     * @param int $ttl Stale threshold in seconds
     * @param int $limit Per-backend recovery bound
     * @return int Recovered durable rows plus notifications (not distinct job count)
     */
    public function recover(string $queue, int $ttl, int $limit): int
    {
        JobStorageRules::validateQueueOrType($queue, 'Queue');
        JobStorageRules::validateStaleRecovery($ttl, $limit);
        $count = $this->storage instanceof SupportsQueueScopedStaleRecovery
            ? $this->storage->recoverStaleJobsForQueue($queue, $ttl, $limit)
            : $this->storage->recoverStaleJobs($ttl);
        if ($this->driver instanceof SupportsStaleRecovery) {
            $count += $this->driver->recoverStaleProcessing($queue, $ttl, $limit);
        }
        return $count;
    }

    /**
     * @param string $queue Queue name
     * @param ReconcileOptions $options Bounded cursor and resource settings
     * @return ReconcileResult|null Null if optional capabilities are unavailable
     */
    public function reconcile(string $queue, ReconcileOptions $options): ?ReconcileResult
    {
        JobStorageRules::validateQueueOrType($queue, 'Queue');
        if (!$this->driver instanceof SupportsBoundedQueueMembership) {
            return null;
        }
        if (
            !$this->storage instanceof SupportsPendingNotificationCursor &&
            !$this->storage instanceof SupportsPendingJobCursor
        ) {
            return null;
        }
        return (new QueueReconciler($this->storage, $this->driver, $this->clock))->reconcile($queue, $options);
    }
}
