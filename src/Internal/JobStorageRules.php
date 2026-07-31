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
        if ($progress === null) {
            return;
        }
        if ($progress < 0) {
            throw new \InvalidArgumentException('Progress must be null or an integer between 0 and 100');
        }
        if ($progress > 100) {
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
     * Normalize an available-at value to the storage UTC timestamp format.
     *
     * The storage layer accepts an absolute Unix timestamp or a date/time
     * object on each `createJobs()` job definition. A null value means "now".
     * Other values and non-positive timestamps are rejected.
     *
     * @param mixed $availableAt Unix timestamp, date/time object, or null for now
     * @param ClockInterface $clock Clock used as the time source
     * @return string Formatted UTC timestamp
     */
    public static function normalizeAvailableAt(mixed $availableAt, ClockInterface $clock): string
    {
        if ($availableAt === null) {
            return $clock->now();
        }
        if (is_int($availableAt)) {
            $timestamp = $availableAt;
        } elseif ($availableAt instanceof \DateTimeInterface) {
            $timestamp = $availableAt->getTimestamp();
        } else {
            throw new \InvalidArgumentException(
                'Available-at must be an integer Unix timestamp or a DateTimeInterface'
            );
        }
        if ($timestamp <= 0) {
            throw new \InvalidArgumentException('Available-at timestamp must be a positive Unix timestamp');
        }

        return gmdate('Y-m-d H:i:s', $timestamp);
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
