<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Contract;

/** Lets a driver return the storage claim it acquired while dequeuing. */
interface SupportsClaimedDequeue
{
    public function dequeueClaimed(string $queue, int $timeoutSeconds): ?ClaimedJob;
}
