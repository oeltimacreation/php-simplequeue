<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Contract;

/** Queue counts observed near sampledAt; cross-key counts need not be atomic. */
final readonly class QueueStatsSnapshot
{
    /**
     * @param int $pending Pending notification count
     * @param int $processing Processing notification count
     * @param int $delayed Delayed notification count
     * @param int $sampledAt Unix sampling timestamp
     */
    public function __construct(public int $pending, public int $processing, public int $delayed, public int $sampledAt)
    {
        if (min($pending, $processing, $delayed) < 0) {
            throw new \InvalidArgumentException('Queue counts must not be negative');
        }
    }
}
