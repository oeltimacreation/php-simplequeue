<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue;

use Oeltima\SimpleQueue\Contract\ClockInterface;

/**
 * Default clock implementation backed by PHP time functions.
 */
final class SystemClock implements ClockInterface
{
    /**
     * Return the current UTC wall-clock time.
     *
     * @return string Formatted UTC date/time
     */
    public function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    /**
     * Return the current Unix timestamp.
     *
     * @return int Current timestamp
     */
    public function timestamp(): int
    {
        return time();
    }

    /**
     * Return monotonic time in seconds for local duration measurement.
     *
     * @return float Monotonic seconds
     */
    public function monotonic(): float
    {
        return hrtime(true) / 1_000_000_000;
    }
}
