<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Contract;

/**
 * Interface for queue drivers that require a worker ID.
 *
 * @deprecated Use SupportsWorkerAwareClaimedDequeue instead; retained for third-party compatibility.
 */
interface SupportsWorkerId
{
    /**
     * Set the worker ID for the queue driver.
     *
     * @param string $workerId The unique identifier of the worker
     */
    public function setWorkerId(string $workerId): void;
}
