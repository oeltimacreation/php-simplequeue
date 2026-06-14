<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Contract;

/**
 * Interface for queue drivers that support enqueueing jobs in batch.
 */
interface SupportsBatchEnqueue
{
    /**
     * Enqueue multiple job IDs efficiently.
     *
     * @param string $queue Queue name
     * @param int[] $jobIds Array of job identifiers
     */
    public function enqueueBatch(string $queue, array $jobIds): void;
}
