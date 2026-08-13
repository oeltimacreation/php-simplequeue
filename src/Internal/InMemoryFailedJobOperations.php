<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Internal;

use Oeltima\SimpleQueue\Contract\JobData;
use Oeltima\SimpleQueue\Contract\JobStatus;

/**
 * Performs failed-job transitions on in-memory storage rows.
 *
 * @phpstan-import-type StoredJobRow from InMemoryJobRow
 * @internal
 */
final class InMemoryFailedJobOperations
{
    private function __construct()
    {
    }

    /**
     * Reset a failed row to a fresh pending state.
     *
     * @param array<int, StoredJobRow> $jobs Storage rows
     * @param int $jobId Job identifier
     * @param string $now Current storage timestamp
     * @return JobData|null Reset job, or null when it is missing or not failed
     */
    public static function requeue(array &$jobs, int $jobId, string $now): ?JobData
    {
        if (!isset($jobs[$jobId]) || $jobs[$jobId]['status'] !== JobStatus::Failed) {
            return null;
        }

        $job = &$jobs[$jobId];
        $job['status'] = JobStatus::Pending;
        $job['attempts'] = 0;
        $job['available_at'] = $now;
        $job['started_at'] = null;
        $job['completed_at'] = null;
        $job['locked_by'] = null;
        $job['locked_at'] = null;
        $job['lease_token'] = null;
        $job['error_message'] = null;
        $job['error_trace'] = null;
        $job['progress'] = null;
        $job['progress_message'] = null;
        $job['result'] = null;
        $job['updated_at'] = $now;

        return JobData::fromRaw($job);
    }

    /**
     * Delete a failed row and return its last durable representation.
     *
     * @param array<int, StoredJobRow> $jobs Storage rows
     * @param int $jobId Job identifier
     * @return JobData|null Deleted job, or null when it is missing or not failed
     */
    public static function purge(array &$jobs, int $jobId): ?JobData
    {
        if (!isset($jobs[$jobId]) || $jobs[$jobId]['status'] !== JobStatus::Failed) {
            return null;
        }

        $job = JobData::fromRaw($jobs[$jobId]);
        unset($jobs[$jobId]);

        return $job;
    }
}
