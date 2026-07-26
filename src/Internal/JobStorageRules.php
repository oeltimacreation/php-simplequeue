<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Internal;

use Oeltima\SimpleQueue\Contract\ClockInterface;
use Oeltima\SimpleQueue\Exception\SerializationException;

/**
 * Domain rules shared by storage implementations.
 *
 * @internal
 */
final class JobStorageRules
{
    /**
     * Validate a nullable progress percentage.
     *
     * @param int|null $progress Progress percentage
     */
    public static function validateProgress(?int $progress): void
    {
        if ($progress !== null && ($progress < 0 || $progress > 100)) {
            throw new \InvalidArgumentException('Progress must be null or an integer between 0 and 100');
        }
    }

    /**
     * Validate retry transition arguments.
     *
     * @param int $attempts Current attempt count
     * @param int $delaySeconds Retry delay in seconds
     */
    public static function validateRetry(int $attempts, int $delaySeconds): void
    {
        if ($attempts < 1 || $delaySeconds < 0) {
            throw new \InvalidArgumentException('Attempts must be positive and retry delay must not be negative');
        }
    }

    /**
     * Validate stale-job recovery arguments.
     *
     * @param int $ttlSeconds Lease time-to-live in seconds
     * @param int $limit Maximum jobs to recover
     */
    public static function validateStaleRecovery(int $ttlSeconds, int $limit): void
    {
        if ($ttlSeconds < 1 || $limit < 1) {
            throw new \InvalidArgumentException('Stale recovery TTL and limit must be positive');
        }
    }

    /**
     * Format a clock timestamp with a relative offset.
     *
     * @param ClockInterface $clock Clock used as the time source
     * @param string $format Date format accepted by gmdate()
     * @param int $offsetSeconds Relative offset in seconds
     * @return string Formatted timestamp
     */
    public static function timestamp(ClockInterface $clock, string $format, int $offsetSeconds): string
    {
        return gmdate($format, $clock->timestamp() + $offsetSeconds);
    }

    /**
     * Encode a value as JSON with domain-specific error context.
     *
     * @param mixed $value Value to encode
     * @param string $context Description included in serialization errors
     * @return string Encoded JSON
     */
    public static function encodeJson(mixed $value, string $context): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new SerializationException(sprintf('Unable to encode %s as JSON', $context), 0, $exception);
        }
    }
}
