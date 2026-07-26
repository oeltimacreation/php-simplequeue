<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Support;

use Oeltima\SimpleQueue\Contract\ClockInterface;

final class FrozenClock implements ClockInterface
{
    /**
     * @param int $time Unix timestamp
     * @param float $monotonicTime Monotonic timestamp
     */
    public function __construct(
        private int $time = 1_700_000_000,
        private float $monotonicTime = 1.0
    ) {
    }

    /**
     * @return string Current database timestamp
     */
    public function now(): string
    {
        return gmdate('Y-m-d H:i:s', $this->time);
    }

    /**
     * @return int Current Unix timestamp
     */
    public function timestamp(): int
    {
        return $this->time;
    }

    /**
     * @return float Current monotonic timestamp
     */
    public function monotonic(): float
    {
        return $this->monotonicTime;
    }

    /**
     * Advance both time sources.
     *
     * @param int $seconds Seconds to advance
     */
    public function advance(int $seconds): void
    {
        $this->time += $seconds;
        $this->monotonicTime += $seconds;
    }
}
