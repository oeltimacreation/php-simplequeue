<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Internal;

/**
 * Provides the validated no-op removal operation for storage-gated drivers.
 *
 * @internal
 */
trait DatabaseJobRemoval
{
    /** @inheritDoc */
    public function remove(string $queue, int $jobId): void
    {
        PositiveJobId::fromInt($jobId, 'jobId must be a positive integer');
    }
}
