<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Contract;

/**
 * Provides operator actions for jobs in the failed terminal state.
 */
interface FailedJobAdminInterface
{
    /**
     * List failed jobs, optionally restricted to a queue.
     *
     * @param string|null $queue Queue name, or null for all queues
     * @param int $limit Maximum number of jobs to return
     * @param int $offset Offset for pagination
     * @return list<JobData> Failed jobs ordered by the storage backend
     */
    public function listFailed(?string $queue = null, int $limit = 100, int $offset = 0): array;

    /**
     * Inspect one failed job.
     *
     * @param int $jobId Job identifier
     * @return JobData|null Failed job, or null when it is missing or no longer failed
     */
    public function inspectFailed(int $jobId): ?JobData;

    /**
     * Reset a failed job for a fresh execution attempt and notify the queue.
     *
     * @param int $jobId Job identifier
     * @return bool True when the failed job was re-queued
     */
    public function requeueFailed(int $jobId): bool;

    /**
     * Permanently remove a failed job and its queue notifications.
     *
     * @param int $jobId Job identifier
     * @return bool True when the failed job was purged
     */
    public function purgeFailed(int $jobId): bool;
}
