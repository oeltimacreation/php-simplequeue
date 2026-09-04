<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Driver;

use Oeltima\SimpleQueue\Contract\ClaimedJob;
use Oeltima\SimpleQueue\Contract\ClockInterface;
use Oeltima\SimpleQueue\Contract\QueueDriverInterface;
use Oeltima\SimpleQueue\Contract\SleeperInterface;
use Oeltima\SimpleQueue\Contract\SupportsBatchEnqueue;
use Oeltima\SimpleQueue\Contract\SupportsClaimedDequeue;
use Oeltima\SimpleQueue\Contract\SupportsJobRemoval;
use Oeltima\SimpleQueue\Contract\SupportsStorageBackedScheduling;
use Oeltima\SimpleQueue\Contract\SupportsWorkerAwareClaimedDequeue;
use Oeltima\SimpleQueue\Contract\JobStorageInterface;
use Oeltima\SimpleQueue\Contract\SupportsWorkerId;
use Oeltima\SimpleQueue\Internal\DatabaseJobRemoval;
use Oeltima\SimpleQueue\Internal\PositiveJobId;
use Oeltima\SimpleQueue\SystemClock;
use Oeltima\SimpleQueue\SystemSleeper;

/**
 * Database polling queue driver.
 *
 * This driver polls the job storage for pending jobs.
 * It's a fallback option when Redis is not available.
 *
 * Note: This driver has higher latency than Redis due to polling.
 */
final class DatabaseQueueDriver implements
    QueueDriverInterface,
    SupportsWorkerId,
    SupportsClaimedDequeue,
    SupportsWorkerAwareClaimedDequeue,
    SupportsStorageBackedScheduling,
    SupportsBatchEnqueue,
    SupportsJobRemoval
{
    use DatabaseJobRemoval;

    private const ERR_INVALID_JOB_ID = 'jobId must be a positive integer';
    private int $pollIntervalMs;
    private string $workerId;
    private readonly SleeperInterface $sleeper;

    /**
     * @param JobStorageInterface $storage Job storage implementation
     * @param int $pollIntervalMs Polling interval in milliseconds (default: 250ms)
     * @param ClockInterface $clock Clock implementation
     * @param SleeperInterface|null $sleeper Sleep boundary for polling
     */
    public function __construct(
        private readonly JobStorageInterface $storage,
        int $pollIntervalMs = 250,
        private readonly ClockInterface $clock = new SystemClock(),
        ?SleeperInterface $sleeper = null
    ) {
        if ($pollIntervalMs <= 0) {
            throw new \InvalidArgumentException('Database poll interval must be positive');
        }
        $this->pollIntervalMs = max(50, $pollIntervalMs);
        $this->workerId = bin2hex(random_bytes(16)); // Default fallback worker ID
        $this->sleeper = $sleeper ?? new SystemSleeper();
    }
    /**
     * Set the worker ID for atomic claim delegation.
     *
     * @deprecated Use dequeueClaimedForWorker() instead; retained for third-party compatibility.
     * @param string $workerId Worker identifier
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
        PositiveJobId::fromInt($jobId, self::ERR_INVALID_JOB_ID);
        // Job is already in the database, nothing to do
    }

    /**
     * Enqueue multiple jobs without per-job notifier calls.
     *
     * @param string $queue Queue name
     * @param int[] $jobIds Job identifiers
     */
    public function enqueueBatch(string $queue, array $jobIds): void
    {
        foreach ($jobIds as $jobId) {
            PositiveJobId::fromInt($jobId, self::ERR_INVALID_JOB_ID);
        }
        // Storage holds the jobs; no notification work is required.
    }
    public function dequeue(string $queue, int $timeoutSeconds): ?int
    {
        return $this->dequeueClaimed($queue, $timeoutSeconds)?->job->id;
    }
    public function dequeueClaimed(string $queue, int $timeoutSeconds): ?ClaimedJob
    {
        return $this->dequeueClaimedForWorker($queue, $timeoutSeconds, $this->workerId);
    }

    /**
     * Dequeue and atomically claim for a caller-supplied worker identity.
     *
     * @param string $queue Queue name
     * @param int $timeoutSeconds Blocking timeout in seconds
     * @param string $workerId Worker identity used for the atomic claim
     * @return ClaimedJob|null Claimed job, or null when none became available
     */
    public function dequeueClaimedForWorker(string $queue, int $timeoutSeconds, string $workerId): ?ClaimedJob
    {
        if ($timeoutSeconds < 0) {
            throw new \InvalidArgumentException('Dequeue timeout must not be negative');
        }
        $deadline = $this->clock->monotonic() + max(0, $timeoutSeconds);

        do {
            $claim = $this->storage->claimNextAvailable($queue, $workerId);
            if ($claim !== null) {
                return $claim;
            }

            if ($this->clock->monotonic() >= $deadline) {
                return null;
            }

            $remaining = $deadline - $this->clock->monotonic();
            $sleepSeconds = min($this->pollIntervalMs / 1000.0, max(0.0, $remaining));
            if ($sleepSeconds > 0) {
                $this->sleeper->sleep($sleepSeconds);
            } else {
                return null;
            }
        } while (true);
    }
    public function ack(string $queue, int $jobId): void
    {
        PositiveJobId::fromInt($jobId, self::ERR_INVALID_JOB_ID);
        // Job status is managed by storage, nothing to do
    }
    public function nack(string $queue, int $jobId, int $delaySeconds = 0): void
    {
        PositiveJobId::fromInt($jobId, self::ERR_INVALID_JOB_ID);
        // Retry is handled by storage scheduleRetry, nothing to do
        // The delaySeconds is already handled via storage->scheduleRetry()
    }
}
