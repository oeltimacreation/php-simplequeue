<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Contract;

/**
 * Interface for queue drivers that support recovering stale processing jobs.
 */
interface SupportsStaleRecovery
{
    /**
     * Recover stale processing jobs back to the pending queue.
     *
     * @param string $queue Queue name
     * @param int $ttlSeconds Time threshold - jobs processing longer than this are considered stale
     * @param int $limit Maximum number of jobs to recover
     * @return int Number of jobs recovered
     */
    public function recoverStaleProcessing(string $queue, int $ttlSeconds, int $limit = 100): int;
}
