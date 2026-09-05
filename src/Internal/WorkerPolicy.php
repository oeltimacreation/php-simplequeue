<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Internal;

/**
 * Pure policy decisions used by the worker lifecycle.
 *
 * @internal
 */
final readonly class WorkerPolicy
{
    /**
     * @param int $retryBaseDelay Base delay used for exponential retry and backoff
     * @param int $retryMaxDelay Maximum retry and backoff delay
     */
    public function __construct(
        private int $retryBaseDelay,
        private int $retryMaxDelay
    ) {
    }

    /**
     * Calculate infrastructure backoff for consecutive errors.
     *
     * @param int $errorCount One-based consecutive error count
     * @return int Backoff delay in seconds
     */
    public function backoffDelay(int $errorCount): int
    {
        return $this->exponentialDelay($errorCount);
    }

    /**
     * Determine whether another job attempt is allowed.
     *
     * @param int $attempts Number of attempts including the failed attempt
     * @param int $maxAttempts Maximum attempts allowed for the job
     * @return RetryDecision Exhaustive retry policy outcome
     */
    public function retryDecision(int $attempts, int $maxAttempts): RetryDecision
    {
        return RetryDecision::forAttempt($attempts, $maxAttempts);
    }

    /**
     * Calculate the delay before the next job attempt.
     *
     * @param int $attempts Number of attempts including the failed attempt
     * @return int Retry delay in seconds
     */
    public function retryDelay(int $attempts): int
    {
        return $this->exponentialDelay($attempts);
    }

    /**
     * Determine whether a fenced storage transition reports lost ownership.
     *
     * @param bool $transitionApplied Result of the fenced storage transition
     * @return bool True when ownership was lost
     */
    public function lostOwnership(bool $transitionApplied): bool
    {
        return !$transitionApplied;
    }

    private function exponentialDelay(int $exponent): int
    {
        $delay = pow($this->retryBaseDelay, $exponent);
        if (!is_finite($delay) || $delay >= $this->retryMaxDelay) {
            return $this->retryMaxDelay;
        }

        return (int) $delay;
    }
}
