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
 *
 * @phpstan-type RunContext array{queue: string, options: ReconcileOptions, started: float}
 * @phpstan-type PageProgress array{cursor: int|null, scanned: int, pageCount: int}
 * @phpstan-type ResultCounts array{scanned: int, restored: int, duplicates: int, invalid: int}
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
        $context = ['queue' => $queue, 'options' => $options, 'started' => $started];
        // Prefer the lean notification cursor so full payload/result JSON is never decoded.
        if ($this->storage instanceof SupportsPendingNotificationCursor) {
            return $this->reconcileNotifications($context);
        }
        return $this->reconcileJobs($context);
    }

    /**
     * Reconcile via lean notifications and batched queue operations.
     *
     * @param RunContext $context Reconciliation run context
     * @return ReconcileResult Reconciliation outcome
     */
    private function reconcileNotifications(array $context): ReconcileResult
    {
        $storage = $this->storage;
        if (!$storage instanceof SupportsPendingNotificationCursor) {
            throw new \LogicException('Storage does not support lean reconciliation');
        }
        $options = $context['options'];
        [$pageCursor, $notifications] = $this->cursorPage(
            $options,
            static fn (?int $cursor, int $limit): array => $storage->scanPendingNotifications(
                $context['queue'],
                $cursor,
                $limit
            )
        );
        if ($this->driver instanceof SupportsBatchQueueReconciliation && $notifications !== []) {
            return $this->reconcileNotificationBatch($context, $notifications, $pageCursor);
        }

        return $this->reconcileNotificationFallback($context, $notifications, $pageCursor);
    }

    /**
     * Legacy full-job cursor path for third-party v1 implementations.
     *
     * @param RunContext $context Reconciliation run context
     * @return ReconcileResult Reconciliation outcome
     */
    private function reconcileJobs(array $context): ReconcileResult
    {
        $storage = $this->storage;
        $driver = $this->driver;
        if (!$storage instanceof SupportsPendingJobCursor || !$driver instanceof SupportsBoundedQueueMembership) {
            throw new \LogicException('Storage and driver do not support bounded reconciliation');
        }
        $options = $context['options'];
        [$pageCursor, $jobs] = $this->cursorPage(
            $options,
            static fn (?int $cursor, int $limit): array => $storage->scanPending(
                $context['queue'],
                $cursor,
                $limit
            )
        );
        $restored = 0;
        $duplicates = 0;
        $invalid = 0;
        $scanned = 0;
        $nextCursor = $pageCursor;
        $processor = new ReconciliationJobProcessor($driver, $this->clock);
        foreach ($jobs as $job) {
            if ($this->deadlineReached($context)) {
                break;
            }
            match ($processor->process($context['queue'], $job, $options)) {
                ReconcileJobOutcome::Restored => $restored++,
                ReconcileJobOutcome::Duplicate => $duplicates++,
                ReconcileJobOutcome::Invalid => $invalid++,
            };
            $scanned++;
            $nextCursor = $job->id;
        }
        $progress = ['cursor' => $nextCursor, 'scanned' => $scanned, 'pageCount' => count($jobs)];
        $counts = compact('scanned', 'restored', 'duplicates', 'invalid');
        return $this->result($this->nextCursor($progress, $options), $counts, $context);
    }

    /**
     * @template T
     * @param ReconcileOptions $options Bounded options
     * @param callable(int|null, int): list<T> $scan Cursor scan
     * @return array{int|null, list<T>}
     */
    private function cursorPage(ReconcileOptions $options, callable $scan): array
    {
        $cursor = $options->cursor;
        $items = $scan($cursor, $options->pageSize);
        if ($items !== [] || $cursor === null) {
            return [$cursor, $items];
        }

        return [null, $scan(null, $options->pageSize)];
    }

    /**
     * @param RunContext $context
     * @param list<\Oeltima\SimpleQueue\Contract\PendingNotification> $notifications
     */
    private function reconcileNotificationBatch(
        array $context,
        array $notifications,
        ?int $pageCursor
    ): ReconcileResult {
        $options = $context['options'];
        $availableAtByJobId = [];
        $order = [];
        $invalid = 0;
        $scanned = 0;
        $nextCursor = $pageCursor;
        foreach ($notifications as $notification) {
            if ($this->deadlineReached($context)) {
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
        [$restored, $duplicates] = $this->reconcileBatch($context, $availableAtByJobId, $order);
        $scanned += count($order);

        $progress = ['cursor' => $nextCursor, 'scanned' => $scanned, 'pageCount' => count($notifications)];
        $counts = compact('scanned', 'restored', 'duplicates', 'invalid');
        return $this->result($this->nextCursor($progress, $options), $counts, $context);
    }

    /**
     * @param RunContext $context
     * @param array<int, int> $availableAtByJobId Validated notification timestamps
     * @param list<int> $order Valid job IDs in cursor order
     * @return array{int, int} Restored and duplicate counts
     */
    private function reconcileBatch(
        array $context,
        array $availableAtByJobId,
        array $order
    ): array {
        $driver = $this->driver;
        if (!$driver instanceof SupportsBatchQueueReconciliation || $availableAtByJobId === []) {
            return [0, 0];
        }
        $options = $context['options'];
        $present = $driver->reconcileNotifications(
            $context['queue'],
            $availableAtByJobId,
            $this->clock->timestamp(),
            $options->membershipScanLimit
        );
        $presentSet = array_flip($present);
        $duplicates = count(array_filter($order, static fn (int $jobId): bool => isset($presentSet[$jobId])));

        return [count($order) - $duplicates, $duplicates];
    }

    /**
     * @param RunContext $context
     * @param list<\Oeltima\SimpleQueue\Contract\PendingNotification> $notifications
     */
    private function reconcileNotificationFallback(
        array $context,
        array $notifications,
        ?int $pageCursor
    ): ReconcileResult {
        $driver = $this->driver;
        if (!$driver instanceof SupportsBoundedQueueMembership) {
            throw new \LogicException('Driver does not support bounded reconciliation');
        }
        $options = $context['options'];
        $processor = new ReconciliationJobProcessor($driver, $this->clock);
        $outcomes = [];
        $nextCursor = $pageCursor;
        foreach ($notifications as $notification) {
            if ($this->deadlineReached($context)) {
                break;
            }
            $outcomes[] = $processor->processNotification(
                $context['queue'],
                $notification->jobId,
                $notification->availableAt,
                $options
            );
            $nextCursor = $notification->jobId;
        }
        $scanned = count($outcomes);

        $progress = ['cursor' => $nextCursor, 'scanned' => $scanned, 'pageCount' => count($notifications)];
        $counts = [
            'scanned' => $scanned,
            'restored' => $this->outcomeCount($outcomes, ReconcileJobOutcome::Restored),
            'duplicates' => $this->outcomeCount($outcomes, ReconcileJobOutcome::Duplicate),
            'invalid' => $this->outcomeCount($outcomes, ReconcileJobOutcome::Invalid),
        ];
        return $this->result($this->nextCursor($progress, $options), $counts, $context);
    }

    /** @param RunContext $context */
    private function deadlineReached(array $context): bool
    {
        return $this->clock->monotonic() - $context['started'] >= $context['options']->maxDurationSeconds;
    }

    /** @param PageProgress $progress */
    private function nextCursor(array $progress, ReconcileOptions $options): ?int
    {
        $pageComplete = $progress['scanned'] === $progress['pageCount'];
        $isLastPage = $progress['scanned'] < $options->pageSize;
        return $pageComplete && $isLastPage ? null : $progress['cursor'];
    }

    /**
     * @param list<ReconcileJobOutcome> $outcomes Reconciliation outcomes
     */
    private function outcomeCount(array $outcomes, ReconcileJobOutcome $expected): int
    {
        return count(array_filter($outcomes, static fn (ReconcileJobOutcome $outcome): bool => $outcome === $expected));
    }

    /**
     * @param ResultCounts $counts
     * @param RunContext $context
     */
    private function result(
        ?int $nextCursor,
        array $counts,
        array $context
    ): ReconcileResult {
        return new ReconcileResult(
            $nextCursor,
            $counts['scanned'],
            $counts['restored'],
            $counts['duplicates'],
            $counts['invalid'],
            $this->clock->monotonic() - $context['started']
        );
    }
}
