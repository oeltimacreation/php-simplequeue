<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Internal;

/**
 * Exhaustive outcome of the worker retry policy.
 *
 * @internal
 */
enum RetryDecision
{
    case Retry;
    case Fail;

    /**
     * Resolve the retry outcome for the current attempt count.
     *
     * @param int $attempts Current attempt count
     * @param int $maxAttempts Maximum allowed attempts
     * @return self Retry policy outcome
     */
    public static function forAttempt(int $attempts, int $maxAttempts): self
    {
        return $attempts < $maxAttempts ? self::Retry : self::Fail;
    }

    /**
     * Determine whether another attempt should be scheduled.
     *
     * @return bool True when the job remains retryable
     */
    public function shouldRetry(): bool
    {
        return $this === self::Retry;
    }
}
