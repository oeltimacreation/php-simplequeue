<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Contract;

/**
 * Provides atomic failed-job state transitions for administrative services.
 */
interface SupportsFailedJobAdministration
{
    /**
     * Reset a failed job to a fresh pending state.
     *
     * @param int $jobId Job identifier
     * @return JobData|null Reset job, or null when it is missing or not failed
     */
    public function requeueFailed(int $jobId): ?JobData;

    /**
     * Delete a failed job and return its last durable representation.
     *
     * @param int $jobId Job identifier
     * @return JobData|null Deleted job, or null when it is missing or not failed
     */
    public function purgeFailed(int $jobId): ?JobData;
}
