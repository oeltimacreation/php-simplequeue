<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue;

use Oeltima\SimpleQueue\Contract\ClockInterface;
use Oeltima\SimpleQueue\Contract\JobStorageInterface;
use Oeltima\SimpleQueue\Contract\QueueDriverInterface;
use Oeltima\SimpleQueue\Contract\SupportsBatchQueueReconciliation;
use Oeltima\SimpleQueue\Contract\SupportsBoundedQueueMembership;
use Oeltima\SimpleQueue\Contract\SupportsPendingJobCursor;
use Oeltima\SimpleQueue\Contract\SupportsPendingNotificationCursor;
use Oeltima\SimpleQueue\Internal\ReconcileJobOutcome;
use Oeltima\SimpleQueue\Internal\ReconciliationJobProcessor;

/**
 * Repairs missing pending notifications with explicit, bounded work.
 *
 * QueueReconciler repairs missing pending notifications; stale-running lease
 * recovery remains a separate Worker/storage responsibility. Duplicates means
 * "already notified or bounded-scan hit"; bounded pending false negatives may
 * still create harmless duplicate delivery under at-least-once.
 */
final class QueueReconciler
{
    public function __construct(
        private readonly JobStorageInterface $storage,
        private readonly QueueDriverInterface $driver,
        private readonly ClockInterface $clock = new SystemClock()
    ) {
    }

    public function reconcile(string $queue, ReconcileOptions $options): ReconcileResult
    {
        if (
            !$this->storage instanceof SupportsPendingJobCursor
            || !$this->driver instanceof SupportsBoundedQueueMembership
        ) {
            throw new \LogicException('Storage and driver do not support bounded reconciliation');
        }

        $started = $this->clock->monotonic();
        // Prefer the lean notification cursor so full payload/result JSON is never decoded.
        if ($this->storage instanceof SupportsPendingNotificationCursor) {
            return $this->reconcileLean($queue, $options, $started);
        }
        return $this->reconcileLegacy($queue, $options, $started);
    }

    /**
     * Reconcile via lean notifications and batched queue operations.
     *
     * @param string $queue Queue name
     * @param ReconcileOptions $options Bounded options
     * @param float $started Monotonic start time
     * @return ReconcileResult Reconciliation outcome
     */
    private function reconcileLean(string $queue, ReconcileOptions $options, float $started): ReconcileResult
    {
        $storage = $this->storage;
        $driver = $this->driver;
        if (!$storage instanceof SupportsPendingNotificationCursor || !$storage instanceof SupportsPendingJobCursor) {
            throw new \LogicException('Storage does not support lean reconciliation');
        }
        if (!$driver instanceof SupportsBoundedQueueMembership) {
            throw new \LogicException('Driver does not support bounded reconciliation');
        }
        $pageCursor = $options->cursor;
        $notifications = $storage->scanPendingNotifications($queue, $pageCursor, $options->pageSize);
        if ($notifications === [] && $options->cursor !== null) {
            $pageCursor = null;
            $notifications = $storage->scanPendingNotifications($queue, null, $options->pageSize);
        }
        $restored = 0;
        $duplicates = 0;
        $invalid = 0;
        $scanned = 0;
        $nextCursor = $pageCursor;
        // Prefer one queue roundtrip per page when the driver supports batching.
        if ($this->driver instanceof SupportsBatchQueueReconciliation && $notifications !== []) {
            $availableAtByJobId = [];
            $order = [];
            foreach ($notifications as $notification) {
                if ($this->clock->monotonic() - $started >= $options->maxDurationSeconds) {
                    break;
                }
                $parsed = ReconciliationJobProcessor::parseTimestamp($notification->availableAt, $this->clock);
                if ($parsed === false || $notification->jobId < 1) {
                    $invalid++;
                    $scanned++;
                    $nextCursor = $notification->jobId;
                    continue;
                }
                $availableAtByJobId[$notification->jobId] = $parsed;
                $order[] = $notification->jobId;
            }
            if ($availableAtByJobId !== []) {
                $present = $this->driver->reconcileNotifications(
                    $queue,
                    $availableAtByJobId,
                    $this->clock->timestamp(),
                    $options->membershipScanLimit
                );
                $presentSet = array_flip($present);
                // Batch was bounded by the soft deadline above; count all batched IDs.
                foreach ($order as $jobId) {
                    if (isset($presentSet[$jobId])) {
                        $duplicates++;
                    } else {
                        $restored++;
                    }
                    $scanned++;
                    $nextCursor = $jobId;
                }
            }
        } else {
            // Fallback per-item path for third-party drivers.
            $processor = new ReconciliationJobProcessor($driver, $this->clock);
            // Need full jobs for legacy processor; fall back to full cursor for this page.
            $jobs = $storage->scanPending($queue, $pageCursor, $options->pageSize);
            if ($jobs === [] && $options->cursor !== null) {
                $jobs = $storage->scanPending($queue, null, $options->pageSize);
            }
            foreach ($jobs as $job) {
                if ($this->clock->monotonic() - $started >= $options->maxDurationSeconds) {
                    break;
                }
                match ($processor->process($queue, $job, $options)) {
                    ReconcileJobOutcome::Restored => $restored++,
                    ReconcileJobOutcome::Duplicate => $duplicates++,
                    ReconcileJobOutcome::Invalid => $invalid++,
                };
                $scanned++;
                $nextCursor = $job->id;
            }
            if ($scanned === count($jobs) && $scanned < $options->pageSize) {
                $nextCursor = null;
            }
            return new ReconcileResult(
                $nextCursor,
                $scanned,
                $restored,
                $duplicates,
                $invalid,
                $this->clock->monotonic() - $started
            );
        }
        if ($scanned === count($notifications) && $scanned < $options->pageSize) {
            $nextCursor = null;
        }
        return new ReconcileResult(
            $nextCursor,
            $scanned,
            $restored,
            $duplicates,
            $invalid,
            $this->clock->monotonic() - $started
        );
    }

    /**
     * Legacy full-job cursor path for third-party v1 implementations.
     *
     * @param string $queue Queue name
     * @param ReconcileOptions $options Bounded options
     * @param float $started Monotonic start time
     * @return ReconcileResult Reconciliation outcome
     */
    private function reconcileLegacy(string $queue, ReconcileOptions $options, float $started): ReconcileResult
    {
        $storage = $this->storage;
        $driver = $this->driver;
        if (!$storage instanceof SupportsPendingJobCursor || !$driver instanceof SupportsBoundedQueueMembership) {
            throw new \LogicException('Storage and driver do not support bounded reconciliation');
        }
        $pageCursor = $options->cursor;
        $jobs = $storage->scanPending($queue, $pageCursor, $options->pageSize);
        if ($jobs === [] && $options->cursor !== null) {
            $pageCursor = null;
            $jobs = $storage->scanPending($queue, null, $options->pageSize);
        }
        $restored = 0;
        $duplicates = 0;
        $invalid = 0;
        $scanned = 0;
        $nextCursor = $pageCursor;
        $processor = new ReconciliationJobProcessor($driver, $this->clock);
        foreach ($jobs as $job) {
            if ($this->clock->monotonic() - $started >= $options->maxDurationSeconds) {
                break;
            }
            match ($processor->process($queue, $job, $options)) {
                ReconcileJobOutcome::Restored => $restored++,
                ReconcileJobOutcome::Duplicate => $duplicates++,
                ReconcileJobOutcome::Invalid => $invalid++,
            };
            $scanned++;
            $nextCursor = $job->id;
        }
        if ($scanned === count($jobs) && $scanned < $options->pageSize) {
            $nextCursor = null;
        }
        return new ReconcileResult(
            $nextCursor,
            $scanned,
            $restored,
            $duplicates,
            $invalid,
            $this->clock->monotonic() - $started
        );
    }
}
