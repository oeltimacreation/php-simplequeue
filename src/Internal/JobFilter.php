<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Internal;

use Oeltima\SimpleQueue\Contract\JobStatus;

/**
 * Status and queue filter shared by storage query implementations.
 *
 * @internal
 */
final readonly class JobFilter
{
    /**
     * @param JobStatus|null $status Optional status filter
     * @param string|null $queue Optional queue filter
     */
    public function __construct(
        public ?JobStatus $status,
        public ?string $queue
    ) {
    }

    /**
     * Determine whether a job matches the configured filters.
     *
     * @param JobStatus $status Job status
     * @param string $queue Job queue
     * @return bool True when the job matches
     */
    public function matches(JobStatus $status, string $queue): bool
    {
        return ($this->status === null || $status === $this->status)
            && ($this->queue === null || $queue === $this->queue);
    }
}
