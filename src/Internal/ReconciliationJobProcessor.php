<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Internal;

use Oeltima\SimpleQueue\Contract\ClockInterface;
use Oeltima\SimpleQueue\Contract\JobData;
use Oeltima\SimpleQueue\Contract\QueueDriverInterface;
use Oeltima\SimpleQueue\Contract\SupportsBoundedQueueMembership;
use Oeltima\SimpleQueue\ReconcileOptions;

/**
 * Reconciles one durable pending job with its queue notification.
 *
 * @internal
 */
final readonly class ReconciliationJobProcessor
{
    /**
     * @param QueueDriverInterface&SupportsBoundedQueueMembership $driver Queue membership and notification operations
     * @param ClockInterface $clock Reconciliation clock
     */
    public function __construct(
        private QueueDriverInterface&SupportsBoundedQueueMembership $driver,
        private ClockInterface $clock
    ) {
    }

    /**
     * Reconcile one pending job.
     *
     * @param string $queue Queue name
     * @param JobData $job Durable pending job
     * @param ReconcileOptions $options Bounded reconciliation options
     * @return ReconcileJobOutcome Observable reconciliation outcome
     */
    public function process(string $queue, JobData $job, ReconcileOptions $options): ReconcileJobOutcome
    {
        if ($job->id < 1) {
            return ReconcileJobOutcome::Invalid;
        }

        $parsedAvailableAt = strtotime($job->availableAt ?? 'now');
        $availableAt = $parsedAvailableAt === false ? $this->clock->timestamp() : $parsedAvailableAt;
        $isDue = $availableAt <= $this->clock->timestamp();
        $exists = $isDue
            ? $this->driver->hasPendingJob($queue, $job->id, $options->membershipScanLimit)
            : $this->driver->hasDelayedJob($queue, $job->id);
        if ($exists) {
            return ReconcileJobOutcome::Duplicate;
        }

        if ($isDue) {
            $this->driver->enqueue($queue, $job->id);
        } else {
            $this->driver->nack($queue, $job->id, max(0, $availableAt - $this->clock->timestamp()));
        }
        return ReconcileJobOutcome::Restored;
    }
}
