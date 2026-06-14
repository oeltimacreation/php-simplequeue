<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Contract;

/**
 * Interface for queue drivers that support pending/delayed job ID listing for reconciliation.
 */
interface SupportsQueueReconciliation
{
    /**
     * Get all pending job IDs in the queue.
     *
     * @param string $queue Queue name
     * @return int[] Pending job IDs
     */
    public function getPendingIds(string $queue): array;

    /**
     * Get all delayed job IDs in the queue.
     *
     * @param string $queue Queue name
     * @return int[] Delayed job IDs
     */
    public function getDelayedIds(string $queue): array;
}
