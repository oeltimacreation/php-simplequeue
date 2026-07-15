<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue;

use Oeltima\SimpleQueue\Contract\ClockInterface;
use Oeltima\SimpleQueue\Contract\JobStorageInterface;
use Oeltima\SimpleQueue\Contract\QueueDriverInterface;
use Oeltima\SimpleQueue\Contract\SupportsBoundedQueueMembership;
use Oeltima\SimpleQueue\Contract\SupportsPendingJobCursor;

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
        $jobs = $this->storage->scanPending($queue, $options->cursor, $options->pageSize);
        if ($jobs === [] && $options->cursor !== null) {
            $jobs = $this->storage->scanPending($queue, null, $options->pageSize);
        }

        $restored = 0;
        $duplicates = 0;
        $invalid = 0;
        foreach ($jobs as $job) {
            if ($this->clock->monotonic() - $started >= $options->maxDurationSeconds) {
                break;
            }
            if ($job->id < 1) {
                $invalid++;
                continue;
            }
            $parsedAvailableAt = strtotime($job->availableAt ?? 'now');
            $availableAt = $parsedAvailableAt === false ? $this->clock->timestamp() : $parsedAvailableAt;
            $isDue = $availableAt <= $this->clock->timestamp();
            $exists = $isDue
                ? $this->driver->hasPendingJob($queue, $job->id, $options->membershipScanLimit)
                : $this->driver->hasDelayedJob($queue, $job->id);
            if ($exists) {
                $duplicates++;
                continue;
            }
            if ($isDue) {
                $this->driver->enqueue($queue, $job->id);
            } else {
                $this->driver->nack($queue, $job->id, max(0, $availableAt - $this->clock->timestamp()));
            }
            $restored++;
        }

        $scanned = count($jobs);
        $nextCursor = $scanned < $options->pageSize || $jobs === [] ? null : $jobs[$scanned - 1]->id;
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
