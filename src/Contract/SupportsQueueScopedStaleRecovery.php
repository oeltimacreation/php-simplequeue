<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Contract;

/** Provides queue-scoped, item-bounded stale-job maintenance. */
interface SupportsQueueScopedStaleRecovery
{
    public function recoverStaleJobsForQueue(string $queue, int $ttlSeconds, int $limit): int;
}
