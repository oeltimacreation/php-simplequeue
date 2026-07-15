<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Driver;

use Oeltima\SimpleQueue\Contract\JobStorageInterface;
use Oeltima\SimpleQueue\Contract\QueueDriverInterface;
use Oeltima\SimpleQueue\Contract\SupportsWorkerId;
use Oeltima\SimpleQueue\Contract\SupportsClaimedDequeue;
use Oeltima\SimpleQueue\Contract\ClaimedJob;
use Oeltima\SimpleQueue\Contract\ClockInterface;
use Oeltima\SimpleQueue\SystemClock;

/**
 * Database polling queue driver.
 *
 * This driver polls the job storage for pending jobs.
 * It's a fallback option when Redis is not available.
 *
 * Note: This driver has higher latency than Redis due to polling.
 */
final class DatabaseQueueDriver implements QueueDriverInterface, SupportsWorkerId, SupportsClaimedDequeue
{
    private const ERR_INVALID_JOB_ID = 'jobId must be a positive integer';

    private int $pollIntervalMs;
    private string $workerId;

    /**
     * @param JobStorageInterface $storage Job storage implementation
     * @param int $pollIntervalMs Polling interval in milliseconds (default: 250ms)
     */
    public function __construct(
        private JobStorageInterface $storage,
        int $pollIntervalMs = 250,
        private readonly ClockInterface $clock = new SystemClock()
    ) {
        $this->pollIntervalMs = max(50, $pollIntervalMs);
        $this->workerId = bin2hex(random_bytes(16)); // Default fallback worker ID
    }

    /**
     * Set the worker ID for atomic claim delegation.
     *
     */
    public function setWorkerId(string $workerId): void
    {
        $this->workerId = $workerId;
    }

    public function isAvailable(): true
    {
        return true;
    }

    public function enqueue(string $queue, int $jobId): void
    {
        if ($jobId <= 0) {
            throw new \InvalidArgumentException(self::ERR_INVALID_JOB_ID);
        }
        // Job is already in the database, nothing to do
    }

    public function dequeue(string $queue, int $timeoutSeconds): ?int
    {
        return $this->dequeueClaimed($queue, $timeoutSeconds)?->job->id;
    }

    public function dequeueClaimed(string $queue, int $timeoutSeconds): ?ClaimedJob
    {
        $deadline = $this->clock->timestamp() + max(0, $timeoutSeconds);

        do {
            $claim = $this->storage->claimNextAvailable($queue, $this->workerId);
            if ($claim !== null) {
                return $claim;
            }

            if ($this->clock->timestamp() >= $deadline) {
                return null;
            }

            usleep($this->pollIntervalMs * 1000);
        } while (true);
    }

    public function ack(string $queue, int $jobId): void
    {
        if ($jobId <= 0) {
            throw new \InvalidArgumentException(self::ERR_INVALID_JOB_ID);
        }
        // Job status is managed by storage, nothing to do
    }

    public function nack(string $queue, int $jobId, int $delaySeconds = 0): void
    {
        if ($jobId <= 0) {
            throw new \InvalidArgumentException(self::ERR_INVALID_JOB_ID);
        }
        // Retry is handled by storage scheduleRetry, nothing to do
        // The delaySeconds is already handled via storage->scheduleRetry()
    }
}
