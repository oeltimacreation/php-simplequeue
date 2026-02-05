<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Contract;

/**
 * Interface for queue driver implementations.
 *
 * Queue drivers handle the low-level queue operations such as
 * enqueueing, dequeueing, acknowledging, and rejecting jobs.
 */
interface QueueDriverInterface
{
    /**
     * Check if the queue driver is available and functional.
     *
     * @return bool True if the driver can be used
     */
    public function isAvailable(): bool;

    /**
     * Add a job ID to the queue.
     *
     * @param string $queue Queue name
     * @param int $jobId Job identifier
     * @throws \RuntimeException If enqueue operation fails
     */
    public function enqueue(string $queue, int $jobId): void;

    /**
     * Remove and return the next job ID from the queue.
     *
     * This is a blocking operation that waits up to $timeoutSeconds
     * for a job to become available.
     *
     * @param string $queue Queue name
     * @param int $timeoutSeconds Maximum time to wait for a job
     * @return int|null Job ID or null if timeout reached
     */
    public function dequeue(string $queue, int $timeoutSeconds): ?int;

    /**
     * Acknowledge successful job processing.
     *
     * This removes the job from the processing queue, indicating
     * that it has been successfully handled.
     *
     * @param string $queue Queue name
     * @param int $jobId Job identifier
     */
    public function ack(string $queue, int $jobId): void;

    /**
     * Reject a job and return it to the queue for retry.
     *
     * This is called when a job fails but should be retried.
     * If delaySeconds > 0, the job should not be available until that time passes.
     *
     * @param string $queue Queue name
     * @param int $jobId Job identifier
     * @param int $delaySeconds Seconds to wait before job is available again (default: 0 = immediate)
     */
    public function nack(string $queue, int $jobId, int $delaySeconds = 0): void;
}
