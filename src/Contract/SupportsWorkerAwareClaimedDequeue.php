<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Contract;

/**
 * Lets a driver atomically claim for a caller-supplied worker identity.
 *
 * Worker prefers this contract so multiple Worker objects in one process do
 * not collide on shared mutable driver state.
 */
interface SupportsWorkerAwareClaimedDequeue
{
    /**
     * Dequeue and atomically claim the next job for the given worker.
     *
     * @param string $queue Queue name
     * @param int $timeoutSeconds Blocking timeout in seconds
     * @param string $workerId Worker identity used for the atomic claim
     * @return ClaimedJob|null Claimed job, or null when none became available
     */
    public function dequeueClaimedForWorker(
        string $queue,
        int $timeoutSeconds,
        string $workerId
    ): ?ClaimedJob;
}
