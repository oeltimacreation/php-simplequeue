<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Exception;

/**
 * A PDO mutation may have committed before the connection failed.
 *
 * Callers must inspect or reconcile durable state rather than retry blindly,
 * because replaying the write could duplicate work or orphan a claim.
 */
final class IndeterminateStorageOutcomeException extends QueueException
{
    /**
     * @param string $operation Storage operation whose outcome is uncertain
     * @param string $message Human-readable guidance to inspect/reconcile state
     * @param int $code Exception code
     * @param \Throwable|null $previous Original connection/commit failure
     */
    public function __construct(
        public readonly string $operation,
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct(
            $message !== '' ? $message : sprintf(
                'Storage operation "%s" is indeterminate; '
                . 'inspect durable state instead of retrying blindly.',
                $operation
            ),
            $code,
            $previous
        );
    }

    /**
     * Create an indeterminate outcome for a named storage operation.
     *
     * @param string $operation Storage operation whose outcome is uncertain
     * @param \Throwable|null $previous Original connection/commit failure
     * @return self Indeterminate outcome exception
     */
    public static function forOperation(string $operation, ?\Throwable $previous = null): self
    {
        return new self(
            $operation,
            sprintf(
                'Storage operation "%s" may have committed; '
                . 'inspect durable state instead of retrying blindly.',
                $operation
            ),
            0,
            $previous
        );
    }
}
