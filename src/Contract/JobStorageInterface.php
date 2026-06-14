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
     * Batch create multiple job records in a single operation.
     *
     * @param array<int, array<string, mixed>> $jobs Array of job definitions
     * @return int[] Array of created job IDs
     */
    public function createJobs(array $jobs): array;

    /**
     * Get job details by ID.
     *
     * @param int $id Job identifier
     * @return JobData|null Job data or null if not found
     */
    public function find(int $id): ?JobData;

    /**
     * Find an active (pending or running) job by request ID.
     *
     * @param string $requestId Request correlation ID
     * @return JobData|null Active job or null if none found
     */
    public function findActiveByRequestId(string $requestId): ?JobData;

    /**
     * Atomically claim the next available job in a queue.
     *
     * @param string $queue Queue name
     * @param string $workerId Worker identifier
     * @return ClaimedJob|null Claimed job or null if no job is available
     */
    public function claimNextAvailable(string $queue, string $workerId): ?ClaimedJob;

    /**
     * Atomically claim a specific job by ID.
     *
     * @param int $id Job identifier
     * @param string $workerId Worker identifier
     * @return ClaimedJob|null Claimed job or null if unavailable
     */
    public function claimById(int $id, string $workerId): ?ClaimedJob;

    /**
     * Mark a job as completed.
     *
     * @param ClaimedJob $claim Claimed job ownership token
     * @param mixed $result Result data to store
     * @return bool True if successfully updated
     */
    public function markCompleted(ClaimedJob $claim, mixed $result = null): bool;

    /**
     * Mark a job as failed.
     *
     * @param ClaimedJob $claim Claimed job ownership token
     * @param string $errorMessage Error message
     * @param string|null $errorTrace Stack trace
     * @return bool True if successfully updated
     */
    public function markFailed(ClaimedJob $claim, string $errorMessage, ?string $errorTrace = null): bool;

    /**
     * Update job progress.
     *
     * @param ClaimedJob $claim Claimed job ownership token
     * @param int|null $progress Progress percentage (0-100)
     * @param string|null $message Progress message
     * @return bool True if successfully updated
     */
    public function updateProgress(ClaimedJob $claim, ?int $progress = null, ?string $message = null): bool;

    /**
     * Schedule a job for retry.
     *
     * @param ClaimedJob $claim Claimed job ownership token
     * @param int $attempts Current attempt count
     * @param int $delaySeconds Delay before next attempt
     * @param string|null $errorMessage Error message from failed attempt
     * @return bool True if successfully updated
     */
    public function scheduleRetry(
        ClaimedJob $claim,
        int $attempts,
        int $delaySeconds,
        ?string $errorMessage = null
    ): bool;

    /**
     * Refresh the lease for a running job.
     *
     * @param ClaimedJob $claim Claimed job ownership token
     * @return bool True if the lease was refreshed
     */
    public function heartbeat(ClaimedJob $claim): bool;

    /**
     * Recover stale/stuck jobs that have been running too long.
     *
     * @param int $ttlSeconds Maximum time a job can be in running state
     * @return int Number of jobs recovered
     */
    public function recoverStaleJobs(int $ttlSeconds): int;

    /**
     * Cancel a pending job.
     *
     * Only jobs in 'pending' status can be cancelled.
     *
     * @param int $id Job identifier
     * @return bool True if the job was successfully cancelled
     */
    public function cancel(int $id): bool;
}
