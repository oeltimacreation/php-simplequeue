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
     * Determine whether another attempt should be scheduled.
     *
     * @return bool True when the job remains retryable
     */
    public function shouldRetry(): bool
    {
        return $this === self::Retry;
    }
}
