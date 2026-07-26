<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Support;

use Oeltima\SimpleQueue\Contract\ClockInterface;

final class FrozenClock implements ClockInterface
{
    public function __construct(
        private int $time = 1_700_000_000,
        private float $monotonicTime = 1.0
    ) {
    }

    public function now(): string
    {
        return gmdate('Y-m-d H:i:s', $this->time);
    }

    public function timestamp(): int
    {
        return $this->time;
    }

    public function monotonic(): float
    {
        return $this->monotonicTime;
    }

    public function advance(int $seconds): void
    {
        $this->time += $seconds;
        $this->monotonicTime += $seconds;
    }
}
