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
        return $this->processNotification($queue, $job->id, $job->availableAt, $options);
    }

    /**
     * Reconcile one lean pending notification.
     *
     * @param string $queue Queue name
     * @param int $jobId Pending job identifier
     * @param string|null $availableAt Stored availability timestamp
     * @param ReconcileOptions $options Bounded reconciliation options
     * @return ReconcileJobOutcome Observable reconciliation outcome
     */
    public function processNotification(
        string $queue,
        int $jobId,
        ?string $availableAt,
        ReconcileOptions $options
    ): ReconcileJobOutcome {
        if ($jobId < 1) {
            return ReconcileJobOutcome::Invalid;
        }

        $parsedAvailableAt = self::parseTimestamp($availableAt);
        if ($parsedAvailableAt === false) {
            return ReconcileJobOutcome::Invalid;
        }
        $availableAt = $parsedAvailableAt;
        $now = $this->clock->timestamp();
        $isDue = $availableAt <= $now;
        // Either structure counts as notified, regardless of due state.
        if (
            $this->driver->hasPendingJob($queue, $jobId, $options->membershipScanLimit)
            || $this->driver->hasDelayedJob($queue, $jobId)
        ) {
            return ReconcileJobOutcome::Duplicate;
        }

        if ($isDue) {
            $this->driver->enqueue($queue, $jobId);
        } else {
            $this->driver->nack($queue, $jobId, max(0, $parsedAvailableAt - $now));
        }
        return ReconcileJobOutcome::Restored;
    }

    /**
     * Parse a stored availability timestamp strictly.
     *
     * @param string|null $availableAt Stored availability timestamp
     * @return int|false Unix timestamp, or false when unparseable
     */
    public static function parseTimestamp(?string $availableAt): int|false
    {
        if ($availableAt === null || $availableAt === '') {
            return false;
        }
        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $availableAt, new \DateTimeZone('UTC'));
        $parseErrors = \DateTimeImmutable::getLastErrors();
        $strict = $parsed !== false
            && ($parseErrors === false || ($parseErrors['warning_count'] === 0 && $parseErrors['error_count'] === 0))
            && $parsed->format('Y-m-d H:i:s') === $availableAt;
        return $strict ? $parsed->getTimestamp() : false;
    }
}
