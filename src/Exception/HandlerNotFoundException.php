<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Exception;

/**
 * Exception thrown when no handler is registered for a job type.
 */
class HandlerNotFoundException extends QueueException
{
    public static function forType(string $type): self
    {
        return new self(sprintf('No handler registered for job type: %s', $type));
    }
}
