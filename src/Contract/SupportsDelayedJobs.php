<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Contract;

/**
 * Interface for queue drivers that support promoting delayed jobs.
 */
interface SupportsDelayedJobs
{
    /**
     * Promote delayed jobs that are now due to the pending queue.
     *
     * @param string $queue Queue name
     * @param int $limit Maximum number of jobs to promote
     * @return int Number of jobs promoted
     */
    public function promoteDelayedJobs(string $queue, int $limit = 100): int;

    /**
     * Add a job to the delayed notification structure.
     *
     * The job becomes visible to workers only after its availability timestamp
     * passes and the delayed structure is promoted.
     *
     * @param string $queue Queue name
     * @param int $jobId Job identifier
     * @param int $availableAt Unix timestamp when the job becomes available
     */
    public function enqueueDelayed(string $queue, int $jobId, int $availableAt): void;

    /**
     * Add multiple jobs to the delayed notification structure in one operation.
     *
     * Delayed-capable drivers batch the notifications into a single network
     * roundtrip. Drivers that only implement {@see enqueueDelayed()} fall back
     * through {@see \Oeltima\SimpleQueue\QueueManager::enqueueDelayedBatch()}
     * to one {@see enqueueDelayed()} call per job.
     *
     * @param string $queue Queue name
     * @param int[] $jobIds Job identifiers
     * @param int $availableAt Unix timestamp when all jobs become available
     */
    public function enqueueDelayedBatch(string $queue, array $jobIds, int $availableAt): void;
}
