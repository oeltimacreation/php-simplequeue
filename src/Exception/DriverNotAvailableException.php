<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Exception;

/**
 * Exception thrown when a queue driver is not available.
 */
class DriverNotAvailableException extends QueueException
{
    public static function redis(): self
    {
        return new self('Redis driver requested but Redis is not available');
    }

    public static function noDriver(): self
    {
        return new self('No queue driver available. Provide either a Redis client or JobStorage implementation.');
    }
}
