<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Contract;

/**
 * Provides wall-clock and monotonic time for queue components.
 */
interface ClockInterface
{
    /**
     * Return the current UTC wall-clock time.
     *
     * @return string Formatted UTC date/time
     */
    public function now(): string;

    /**
     * Return the current Unix timestamp.
     *
     * @return int Current timestamp
     */
    public function timestamp(): int;

    /**
     * Return monotonic time in seconds for local duration measurement.
     *
     * @return float Monotonic seconds
     */
    public function monotonic(): float;
}
