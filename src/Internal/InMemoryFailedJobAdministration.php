<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Internal;

use Oeltima\SimpleQueue\Contract\JobData;

/**
 * Exposes failed-job operations on in-memory storage without growing its core class.
 *
 * @mixin \Oeltima\SimpleQueue\Storage\InMemoryJobStorage
 * @internal
 */
trait InMemoryFailedJobAdministration
{
    /** @inheritDoc */
    public function requeueFailed(int $jobId): ?JobData
    {
        return InMemoryFailedJobOperations::requeue($this->jobs, $jobId, $this->now());
    }

    /** @inheritDoc */
    public function purgeFailed(int $jobId): ?JobData
    {
        return InMemoryFailedJobOperations::purge($this->jobs, $jobId);
    }
}
