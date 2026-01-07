<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Contract;

/**
 * Interface for job storage implementations.
 *
 * Job storage handles persistence of job data including creation,
 * status updates, progress tracking, and querying.
 */
interface JobStorageInterface
{
    /**
     * Create a new job record.
     *
     * @param string $type Job type identifier
     * @param array<string, mixed> $payload Job payload data
     * @param string $queue Queue name
     * @param int $maxAttempts Maximum retry attempts
     * @param string|null $requestId Optional request correlation ID
     * @return int The created job ID
     */
    public function createJob(
        string $type,
        array $payload,
        string $queue = 'default',
        int $maxAttempts = 3,
        ?string $requestId = null
    ): int;

    /**
     * Get job details by ID.
     *
     * @param int $id Job identifier
     * @return JobData|null Job data or null if not found
     */
    public function find(int $id): ?JobData;

    /**
     * Get the next pending job ID for a queue.
     *
     * @param string $queue Queue name
     * @return int|null Job ID or null if no pending jobs
     */
    public function getNextPendingJobId(string $queue = 'default'): ?int;

    /**
     * Claim a job for processing by a worker.
     *
     * @param int $id Job identifier
     * @param string $workerId Worker identifier
     * @return bool True if successfully claimed
     */
    public function claimJob(int $id, string $workerId): bool;

    /**
     * Mark a job as completed.
     *
     * @param int $id Job identifier
     * @param mixed $result Result data to store
     * @return bool True if successfully updated
     */
    public function markCompleted(int $id, mixed $result = null): bool;

    /**
     * Mark a job as failed.
     *
     * @param int $id Job identifier
     * @param string $errorMessage Error message
     * @param string|null $errorTrace Stack trace
     * @return bool True if successfully updated
     */
    public function markFailed(int $id, string $errorMessage, ?string $errorTrace = null): bool;

    /**
     * Update job progress.
     *
     * @param int $id Job identifier
     * @param int|null $progress Progress percentage (0-100)
     * @param string|null $message Progress message
     * @return bool True if successfully updated
     */
    public function updateProgress(int $id, ?int $progress = null, ?string $message = null): bool;

    /**
     * Schedule a job for retry.
     *
     * @param int $id Job identifier
     * @param int $attempts Current attempt count
     * @param int $delaySeconds Delay before next attempt
     * @param string|null $errorMessage Error message from failed attempt
     * @return bool True if successfully updated
     */
    public function scheduleRetry(int $id, int $attempts, int $delaySeconds, ?string $errorMessage = null): bool;

    /**
     * Recover stale/stuck jobs that have been running too long.
     *
     * @param int $ttlSeconds Maximum time a job can be in running state
     * @return int Number of jobs recovered
     */
    public function recoverStaleJobs(int $ttlSeconds): int;
}
