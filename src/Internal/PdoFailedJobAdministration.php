<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Internal;

use Oeltima\SimpleQueue\Contract\JobData;

/**
 * Exposes failed-job operations on PDO storage without growing its core class.
 *
 * @mixin \Oeltima\SimpleQueue\Storage\PdoJobStorage
 * @internal
 */
trait PdoFailedJobAdministration
{
    /** @inheritDoc */
    public function requeueFailed(int $jobId): ?JobData
    {
        return PdoFailedJobOperations::requeue(
            $this->table,
            $this->now(),
            $jobId,
            [
                'execute' => $this->execute(...),
                'find' => $this->find(...),
            ]
        );
    }

    /** @inheritDoc */
    public function purgeFailed(int $jobId): ?JobData
    {
        return PdoFailedJobOperations::purge(
            $this->table,
            $jobId,
            $this->execute(...),
            $this->find(...)
        );
    }
}
