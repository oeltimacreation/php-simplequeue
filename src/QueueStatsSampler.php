<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue;

use Oeltima\SimpleQueue\Contract\ClockInterface;
use Oeltima\SimpleQueue\Contract\QueueStatsInterface;
use Oeltima\SimpleQueue\Contract\QueueStatsSnapshot;
use Oeltima\SimpleQueue\Contract\SupportsQueueStatsSnapshot;

/** Bounded, single-queue cache for event-listener telemetry. */
final class QueueStatsSampler
{
    private ?QueueStatsSnapshot $snapshot = null;
    private float $sampledAt = 0;

    /**
     * @param QueueStatsInterface $stats Statistics-capable driver
     * @param string $queue Queue name
     * @param float $interval Sampling interval in seconds
     * @param ClockInterface $clock Sampling clock
     */
    public function __construct(
        private readonly QueueStatsInterface $stats,
        private readonly string $queue = 'default',
        private readonly float $interval = 5.0,
        private readonly ClockInterface $clock = new SystemClock()
    ) {
        if (!is_finite($interval) || $interval <= 0) {
            throw new \InvalidArgumentException('Statistics sampling interval must be finite and positive');
        }
    }

    /** @return QueueStatsSnapshot Cached or freshly sampled counts */
    public function sample(): QueueStatsSnapshot
    {
        $now = $this->clock->monotonic();
        if ($this->snapshot !== null && $now - $this->sampledAt < $this->interval) {
            return $this->snapshot;
        }
        $this->snapshot = $this->stats instanceof SupportsQueueStatsSnapshot
            ? $this->stats->getStatsSnapshot($this->queue)
            : new QueueStatsSnapshot(
                $this->stats->getPendingCount($this->queue),
                $this->stats->getProcessingCount($this->queue),
                $this->stats->getDelayedCount($this->queue),
                $this->clock->timestamp()
            );
        $this->sampledAt = $now;
        return $this->snapshot;
    }
}
