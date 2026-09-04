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
     * Membership in either pending or delayed counts as already notified,
     * regardless of whether the job is currently due (at-least-once delivery
     * permits harmless duplicates from bounded-scan false negatives).
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
        if ($parsedAvailableAt === false) {
            return ReconcileJobOutcome::Invalid;
        }
        $availableAt = $parsedAvailableAt;
        $now = $this->clock->timestamp();
        $isDue = $availableAt <= $now;
        // Either structure counts as notified, regardless of due state.
        if (
            $this->driver->hasPendingJob($queue, $job->id, $options->membershipScanLimit)
            || $this->driver->hasDelayedJob($queue, $job->id)
        ) {
            return ReconcileJobOutcome::Duplicate;
        }

        if ($isDue) {
            $this->driver->enqueue($queue, $job->id);
        } else {
            $this->driver->nack($queue, $job->id, max(0, $availableAt - $now));
        }
        return ReconcileJobOutcome::Restored;
    }

    /**
     * Parse a stored availability timestamp strictly.
     *
     * @param string|null $availableAt Stored availability timestamp
     * @return int|false Unix timestamp, or false when unparseable
     */
    public static function parseTimestamp(?string $availableAt, ClockInterface $clock): int|false
    {
        if ($availableAt === null || $availableAt === '') {
            return $clock->timestamp();
        }
        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $availableAt, new \DateTimeZone('UTC'));
        if ($parsed !== false) {
            return $parsed->getTimestamp();
        }
        $fallback = strtotime($availableAt . ' UTC');
        return $fallback === false ? false : $fallback;
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
        return self::parseTimestamp($availableAt, $this->clock);
    }
}
