<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Contract;

/** Optional aggregate observation without three independent client roundtrips. */
interface SupportsQueueStatsSnapshot extends QueueStatsInterface
{
    /**
     * @param string $queue Queue to observe
     * @return QueueStatsSnapshot Counts and observation time
     */
    public function getStatsSnapshot(string $queue): QueueStatsSnapshot;
}
