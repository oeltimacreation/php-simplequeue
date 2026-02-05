<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Driver;

use Oeltima\SimpleQueue\Contract\JobStorageInterface;
use Oeltima\SimpleQueue\Contract\QueueDriverInterface;

/**
 * Database polling queue driver.
 *
 * This driver polls the job storage for pending jobs.
 * It's a fallback option when Redis is not available.
 *
 * Note: This driver has higher latency than Redis due to polling.
 */
final class DatabaseQueueDriver implements QueueDriverInterface
{
    private const ERR_INVALID_JOB_ID = 'jobId must be a positive integer';

    private JobStorageInterface $storage;
    private int $pollIntervalMs;

    /**
     * @param JobStorageInterface $storage Job storage implementation
     * @param int $pollIntervalMs Polling interval in milliseconds (default: 250ms)
     */
    public function __construct(JobStorageInterface $storage, int $pollIntervalMs = 250)
    {
        $this->storage = $storage;
        $this->pollIntervalMs = max(50, $pollIntervalMs);
    }

    public function isAvailable(): bool
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
        $deadline = time() + max(0, $timeoutSeconds);

        do {
            $jobId = $this->storage->getNextPendingJobId($queue);
            if (!empty($jobId)) {
                return $jobId;
            }

            if (time() >= $deadline) {
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
