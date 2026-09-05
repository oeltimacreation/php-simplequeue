<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Contract;

/**
 * Deterministic sleep boundary for polling and backoff.
 */
interface SleeperInterface
{
    /**
     * Sleep for the given number of seconds.
     *
     * @param float $seconds Finite, non-negative duration in seconds
     */
    public function sleep(float $seconds): void;
}
