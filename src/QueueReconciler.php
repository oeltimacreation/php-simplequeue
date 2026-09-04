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
            !$this->storage instanceof SupportsPendingNotificationCursor
            && !$this->storage instanceof SupportsPendingJobCursor
        ) {
            throw new \LogicException('Storage does not support bounded reconciliation');
        }
        if (!$this->driver instanceof SupportsBoundedQueueMembership) {
            throw new \LogicException('Driver does not support bounded reconciliation');
        }

        $started = $this->clock->monotonic();
        // Prefer the lean notification cursor so full payload/result JSON is never decoded.
        if ($this->storage instanceof SupportsPendingNotificationCursor) {
            return $this->reconcileNotifications($queue, $options, $started);
        }
        return $this->reconcileJobs($queue, $options, $started);
    }

    /**
     * Reconcile via lean notifications and batched queue operations.
     *
     * @param string $queue Queue name
     * @param ReconcileOptions $options Bounded options
     * @param float $started Monotonic start time
     * @return ReconcileResult Reconciliation outcome
     */
    private function reconcileNotifications(string $queue, ReconcileOptions $options, float $started): ReconcileResult
    {
        $storage = $this->storage;
        if (!$storage instanceof SupportsPendingNotificationCursor) {
            throw new \LogicException('Storage does not support lean reconciliation');
        }
        [$pageCursor, $notifications] = $this->notificationPage($storage, $queue, $options);
        if ($this->driver instanceof SupportsBatchQueueReconciliation && $notifications !== []) {
            return $this->reconcileNotificationBatch($queue, $notifications, $pageCursor, $options, $started);
        }

        return $this->reconcileNotificationFallback($queue, $notifications, $pageCursor, $options, $started);
    }

    /**
     * Legacy full-job cursor path for third-party v1 implementations.
     *
     * @param string $queue Queue name
     * @param ReconcileOptions $options Bounded options
     * @param float $started Monotonic start time
     * @return ReconcileResult Reconciliation outcome
     */
    private function reconcileJobs(string $queue, ReconcileOptions $options, float $started): ReconcileResult
    {
        $storage = $this->storage;
        $driver = $this->driver;
        if (!$storage instanceof SupportsPendingJobCursor || !$driver instanceof SupportsBoundedQueueMembership) {
            throw new \LogicException('Storage and driver do not support bounded reconciliation');
        }
        [$pageCursor, $jobs] = $this->jobPage($storage, $queue, $options);
        $restored = 0;
        $duplicates = 0;
        $invalid = 0;
        $scanned = 0;
        $nextCursor = $pageCursor;
        $processor = new ReconciliationJobProcessor($driver, $this->clock);
        foreach ($jobs as $job) {
            if ($this->deadlineReached($started, $options)) {
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
        return $this->result(
            $this->nextCursor($nextCursor, $scanned, count($jobs), $options),
            $scanned,
            $restored,
            $duplicates,
            $invalid,
            $started
        );
    }

    /**
     * @param SupportsPendingNotificationCursor $storage Lean cursor storage
     * @return array{?int, list<\Oeltima\SimpleQueue\Contract\PendingNotification>}
     */
    private function notificationPage(
        SupportsPendingNotificationCursor $storage,
        string $queue,
        ReconcileOptions $options
    ): array {
        $cursor = $options->cursor;
        $notifications = $storage->scanPendingNotifications($queue, $cursor, $options->pageSize);
        if ($notifications !== [] || $cursor === null) {
            return [$cursor, $notifications];
        }

        return [null, $storage->scanPendingNotifications($queue, null, $options->pageSize)];
    }

    /**
     * @param SupportsPendingJobCursor $storage Legacy cursor storage
     * @return array{?int, list<\Oeltima\SimpleQueue\Contract\JobData>}
     */
    private function jobPage(SupportsPendingJobCursor $storage, string $queue, ReconcileOptions $options): array
    {
        $cursor = $options->cursor;
        $jobs = $storage->scanPending($queue, $cursor, $options->pageSize);
        if ($jobs !== [] || $cursor === null) {
            return [$cursor, $jobs];
        }

        return [null, $storage->scanPending($queue, null, $options->pageSize)];
    }

    /** @param list<\Oeltima\SimpleQueue\Contract\PendingNotification> $notifications */
    private function reconcileNotificationBatch(
        string $queue,
        array $notifications,
        ?int $pageCursor,
        ReconcileOptions $options,
        float $started
    ): ReconcileResult {
        $availableAtByJobId = [];
        $order = [];
        $invalid = 0;
        $scanned = 0;
        $nextCursor = $pageCursor;
        foreach ($notifications as $notification) {
            if ($this->deadlineReached($started, $options)) {
                break;
            }
            $parsed = ReconciliationJobProcessor::parseTimestamp($notification->availableAt);
            if ($parsed === false || $notification->jobId < 1) {
                $invalid++;
                $scanned++;
                $nextCursor = $notification->jobId;
                continue;
            }
            $availableAtByJobId[$notification->jobId] = $parsed;
            $order[] = $notification->jobId;
            $nextCursor = $notification->jobId;
        }
        [$restored, $duplicates] = $this->reconcileBatch($queue, $availableAtByJobId, $order, $options);
        $scanned += count($order);

        return $this->result(
            $this->nextCursor($nextCursor, $scanned, count($notifications), $options),
            $scanned,
            $restored,
            $duplicates,
            $invalid,
            $started
        );
    }

    /**
     * @param array<int, int> $availableAtByJobId Validated notification timestamps
     * @param list<int> $order Valid job IDs in cursor order
     * @return array{int, int} Restored and duplicate counts
     */
    private function reconcileBatch(
        string $queue,
        array $availableAtByJobId,
        array $order,
        ReconcileOptions $options
    ): array {
        $driver = $this->driver;
        if (!$driver instanceof SupportsBatchQueueReconciliation || $availableAtByJobId === []) {
            return [0, 0];
        }
        $present = $driver->reconcileNotifications(
            $queue,
            $availableAtByJobId,
            $this->clock->timestamp(),
            $options->membershipScanLimit
        );
        $presentSet = array_flip($present);
        $duplicates = count(array_filter($order, static fn (int $jobId): bool => isset($presentSet[$jobId])));

        return [count($order) - $duplicates, $duplicates];
    }

    /** @param list<\Oeltima\SimpleQueue\Contract\PendingNotification> $notifications */
    private function reconcileNotificationFallback(
        string $queue,
        array $notifications,
        ?int $pageCursor,
        ReconcileOptions $options,
        float $started
    ): ReconcileResult {
        $driver = $this->driver;
        if (!$driver instanceof SupportsBoundedQueueMembership) {
            throw new \LogicException('Driver does not support bounded reconciliation');
        }
        $processor = new ReconciliationJobProcessor($driver, $this->clock);
        $outcomes = [];
        $nextCursor = $pageCursor;
        foreach ($notifications as $notification) {
            if ($this->deadlineReached($started, $options)) {
                break;
            }
            $outcomes[] = $processor->processNotification(
                $queue,
                $notification->jobId,
                $notification->availableAt,
                $options
            );
            $nextCursor = $notification->jobId;
        }
        $scanned = count($outcomes);

        return $this->result(
            $this->nextCursor($nextCursor, $scanned, count($notifications), $options),
            $scanned,
            $this->outcomeCount($outcomes, ReconcileJobOutcome::Restored),
            $this->outcomeCount($outcomes, ReconcileJobOutcome::Duplicate),
            $this->outcomeCount($outcomes, ReconcileJobOutcome::Invalid),
            $started
        );
    }

    private function deadlineReached(float $started, ReconcileOptions $options): bool
    {
        return $this->clock->monotonic() - $started >= $options->maxDurationSeconds;
    }

    private function nextCursor(
        ?int $cursor,
        int $scanned,
        int $pageCount,
        ReconcileOptions $options
    ): ?int {
        return $scanned === $pageCount && $scanned < $options->pageSize ? null : $cursor;
    }

    /**
     * @param list<ReconcileJobOutcome> $outcomes Reconciliation outcomes
     */
    private function outcomeCount(array $outcomes, ReconcileJobOutcome $expected): int
    {
        return count(array_filter($outcomes, static fn (ReconcileJobOutcome $outcome): bool => $outcome === $expected));
    }

    private function result(
        ?int $nextCursor,
        int $scanned,
        int $restored,
        int $duplicates,
        int $invalid,
        float $started
    ): ReconcileResult {
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
