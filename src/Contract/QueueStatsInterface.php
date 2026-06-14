<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Contract;

/**
 * Interface for queue drivers that support retrieving queue statistics.
 */
interface QueueStatsInterface
{
    /**
     * Get the count of pending jobs in a queue.
     *
     * @param string $queue Queue name
     * @return int Number of pending jobs
     */
    public function getPendingCount(string $queue): int;

    /**
     * Get the count of jobs currently being processed.
     *
     * @param string $queue Queue name
     * @return int Number of processing jobs
     */
    public function getProcessingCount(string $queue): int;

    /**
     * Get the count of delayed jobs waiting for retry.
     *
     * @param string $queue Queue name
     * @return int Number of delayed jobs
     */
    public function getDelayedCount(string $queue): int;
}
