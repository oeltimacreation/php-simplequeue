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
     * Determine whether an exception represents an infrastructure failure.
     *
     * @param \Throwable $exception Failure raised by the worker loop
     * @return bool True when infrastructure backoff should be applied
     */
    public function isInfrastructureException(\Throwable $exception): bool
    {
        if ($exception instanceof \PDOException || $exception instanceof \RedisException) {
            return true;
        }

        return str_starts_with($exception::class, 'Predis\\');
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
        return $attempts < $maxAttempts ? RetryDecision::Retry : RetryDecision::Fail;
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
     * @return OwnershipOutcome Exhaustive ownership outcome
     */
    public function ownershipOutcome(bool $transitionApplied): OwnershipOutcome
    {
        return $transitionApplied ? OwnershipOutcome::Owned : OwnershipOutcome::Lost;
    }

    private function exponentialDelay(int $exponent): int
    {
        return min($this->retryMaxDelay, (int) pow($this->retryBaseDelay, $exponent));
    }
}
