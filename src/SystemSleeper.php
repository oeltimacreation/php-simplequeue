<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue;

use Oeltima\SimpleQueue\Contract\SleeperInterface;

/**
 * System sleeper using usleep for production polling and backoff.
 */
final class SystemSleeper implements SleeperInterface
{
    /**
     * Sleep for the given number of seconds.
     *
     * @param float $seconds Finite, non-negative duration in seconds
     */
    public function sleep(float $seconds): void
    {
        if (!is_finite($seconds) || $seconds < 0) {
            throw new \InvalidArgumentException('Sleep duration must be finite and non-negative');
        }
        if ($seconds === 0.0) {
            return;
        }
        $microseconds = (int) min($seconds * 1_000_000, PHP_INT_MAX);
        if ($microseconds > 0) {
            usleep($microseconds);
        }
    }
}
