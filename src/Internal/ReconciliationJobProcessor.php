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

        $parsedAvailableAt = $this->parseAvailableAt($job->availableAt);
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

    /**
     * Parse a stored availability timestamp as UTC.
     *
     * Storage implementations write availability timestamps with gmdate() in
     * UTC. Parsing those strings with the server default timezone would shift
     * due/not-due reconciliation decisions by the timezone offset on non-UTC
     * hosts, so the storage format is decoded against the UTC timezone
     * explicitly. Unparseable values fall back to a UTC-annotated strtotime()
     * so alternate storage shapes still reconcile.
     *
     * @param string|null $availableAt Stored availability timestamp, or null for now
     * @return int|false Unix timestamp, or false when the value cannot be parsed
     */
    private function parseAvailableAt(?string $availableAt): int|false
    {
        if ($availableAt === null || $availableAt === '') {
            return $this->clock->timestamp();
        }

        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $availableAt, new \DateTimeZone('UTC'));
        if ($parsed !== false) {
            return $parsed->getTimestamp();
        }

        $fallback = strtotime($availableAt . ' UTC');

        return $fallback === false ? false : $fallback;
    }
}
