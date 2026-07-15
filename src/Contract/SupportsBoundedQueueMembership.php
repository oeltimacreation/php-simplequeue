<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Contract;

/** Provides bounded membership checks for reconciliation. */
interface SupportsBoundedQueueMembership
{
    /** A bounded pending-list check; false negatives may cause harmless duplicate notifications. */
    public function hasPendingJob(string $queue, int $jobId, int $maxElements): bool;

    /** Check delayed ZSET membership. */
    public function hasDelayedJob(string $queue, int $jobId): bool;
}
