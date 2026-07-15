<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Contract;

/** Provides removal of all queue notifications for a job. */
interface SupportsJobRemoval
{
    /**
     * Remove pending, delayed, and processing notifications for a job. This is idempotent.
     *
     * @param string $queue Queue name
     * @param int $jobId Positive job ID
     */
    public function remove(string $queue, int $jobId): void;
}
