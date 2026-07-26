<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue;

use Oeltima\SimpleQueue\Contract\ClockInterface;
use Oeltima\SimpleQueue\Contract\JobStorageInterface;
use Oeltima\SimpleQueue\Contract\QueueDriverInterface;
use Oeltima\SimpleQueue\Contract\SupportsBoundedQueueMembership;
use Oeltima\SimpleQueue\Contract\SupportsPendingJobCursor;
use Oeltima\SimpleQueue\Internal\ReconcileJobOutcome;
use Oeltima\SimpleQueue\Internal\ReconciliationJobProcessor;

/** Repairs storage-to-notifier divergence with explicit, bounded work. */
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
        $pageCursor = $options->cursor;
        $jobs = $this->storage->scanPending($queue, $pageCursor, $options->pageSize);
        if ($jobs === [] && $options->cursor !== null) {
            $pageCursor = null;
            $jobs = $this->storage->scanPending($queue, null, $options->pageSize);
        }
        $restored = 0;
        $duplicates = 0;
        $invalid = 0;
        $scanned = 0;
        $nextCursor = $pageCursor;
        $processor = new ReconciliationJobProcessor($this->driver, $this->clock);
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
