<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Internal;

use Oeltima\SimpleQueue\Contract\ClockInterface;
use Oeltima\SimpleQueue\Exception\SerializationException;

/**
 * Domain rules shared by storage implementations.
 *
 * @internal
 * @phpstan-type ValidatedJobShape array{
 *     type: non-empty-string,
 *     payload: array<mixed, mixed>,
 *     encodedPayload: string,
 *     queue: non-empty-string,
 *     maxAttempts: int,
 *     requestId: non-empty-string|null,
 *     availableAt: string
 * }
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
     * Validate the complete progress update before touching storage.
     *
     * @param int|null $progress Progress percentage
     * @param string|null $message Optional progress message
     */
    public static function validateProgressUpdate(?int $progress, ?string $message): void
    {
        self::validateProgress($progress);
        if ($message !== null) {
            self::validateBoundedString($message, 'Progress message');
        }
    }

    /**
     * Validate retry transition arguments.
     *
     * Non-negative persisted attempt counts are accepted so a graceful
     * pre-execution release can reuse the unchanged count; handler-driven
     * retries always pass a positive count.
     *
     * @param int $attempts Current attempt count
     * @param int $delaySeconds Retry delay in seconds
     * @param int $maxAttempts Maximum failed executions allowed
     */
    public static function validateRetry(int $attempts, int $delaySeconds, int $maxAttempts): void
    {
        if ($attempts < 0 || $delaySeconds < 0) {
            throw new \InvalidArgumentException('Attempts must not be negative and retry delay must not be negative');
        }
        if ($attempts >= $maxAttempts) {
            throw new \InvalidArgumentException('Retry attempts must be less than maximum attempts');
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

    /**
     * Validate a storage table name as one or two dot-separated identifiers.
     *
     * @param string $table Table name, optionally schema-qualified
     * @return string Validated table name
     */
    public static function validateTableName(string $table): string
    {
        $segments = explode('.', $table);
        if ($table === '' || count($segments) > 2) {
            throw new \InvalidArgumentException('Table name must be one or two dot-separated identifiers');
        }
        foreach ($segments as $segment) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $segment) !== 1) {
                throw new \InvalidArgumentException('Table name contains an invalid identifier');
            }
        }

        return $table;
    }

    /**
     * Validate a positive job identifier.
     *
     * @param int $id Job identifier
     * @return int Validated job identifier
     */
    public static function validatePositiveId(int $id): int
    {
        if ($id < 1) {
            throw new \InvalidArgumentException('Job ID must be a positive integer');
        }

        return $id;
    }

    /**
     * Validate a non-empty queue or type value within storage limits.
     *
     * @param string $value Queue or type value
     * @param string $field Field name for error messages
     * @return string Validated value
     */
    public static function validateQueueOrType(string $value, string $field): string
    {
        if (trim($value) === '') {
            throw new \InvalidArgumentException(sprintf('%s must not be empty', $field));
        }
        if (strlen($value) > 255) {
            throw new \InvalidArgumentException(sprintf('%s must not exceed 255 bytes', $field));
        }

        return $value;
    }

    /**
     * Validate a bounded string column (queue, type, request/worker ID, progress message).
     *
     * @param string $value Column value
     * @param string $field Field name for error messages
     * @return string Validated value
     */
    public static function validateBoundedString(string $value, string $field): string
    {
        if (strlen($value) > 255) {
            throw new \InvalidArgumentException(sprintf('%s must not exceed 255 bytes', $field));
        }

        return $value;
    }

    /**
     * Validate a non-empty worker identifier within the shared schema bound.
     *
     * @param string $workerId Worker identifier
     * @return string Validated worker identifier
     */
    public static function validateWorkerId(string $workerId): string
    {
        if (trim($workerId) === '') {
            throw new \InvalidArgumentException('Worker ID must not be empty');
        }
        return self::validateBoundedString($workerId, 'Worker ID');
    }

    /**
     * Validate a max-attempts value.
     *
     * @param int $maxAttempts Maximum retry attempts
     * @return int Validated value
     */
    public static function validateMaxAttempts(int $maxAttempts): int
    {
        if ($maxAttempts < 1) {
            throw new \InvalidArgumentException('Maximum attempts must be at least 1');
        }

        return $maxAttempts;
    }

    /**
     * Validate a non-negative integer (retry counts, delays, retention days, offsets).
     *
     * @param int $value Value to validate
     * @param string $field Field name for error messages
     * @return int Validated value
     */
    public static function validateNonNegative(int $value, string $field): int
    {
        if ($value < 0) {
            throw new \InvalidArgumentException(sprintf('%s must not be negative', $field));
        }

        return $value;
    }

    /**
     * Validate a positive limit.
     *
     * @param int $limit Limit value
     * @param string $field Field name for error messages
     * @return int Validated value
     */
    public static function validatePositiveLimit(int $limit, string $field = 'Limit'): int
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException(sprintf('%s must be positive', $field));
        }

        return $limit;
    }

    /**
     * Validate a single job definition before any row is mutated or ID consumed.
     *
     * @param array<string, mixed> $job Job definition
     * @param ClockInterface $clock Clock used as the time source
     * @return ValidatedJobShape Normalized definition
     */
    public static function validateJobDefinition(array $job, ClockInterface $clock): array
    {
        $type = self::jobType($job);
        $queue = self::jobQueue($job);
        $payload = self::jobPayload($job);
        // Encode eagerly so serialization failure precedes any mutation/ID consumption.
        $encodedPayload = self::encodeJson($payload, 'job payload');

        return [
            'type' => $type,
            'payload' => $payload,
            'encodedPayload' => $encodedPayload,
            'queue' => $queue,
            'maxAttempts' => self::jobMaxAttempts($job),
            'requestId' => self::jobRequestId($job),
            'availableAt' => self::jobAvailableAt($job, $clock),
        ];
    }

    /**
     * @param array<string, mixed> $job
     * @return non-empty-string
     */
    private static function jobType(array $job): string
    {
        $type = $job['type'] ?? null;
        if (!is_string($type)) {
            throw new \InvalidArgumentException('Job type must be a non-empty string');
        }
        if ($type === '' || trim($type) === '') {
            throw new \InvalidArgumentException('Job type must be a non-empty string');
        }
        self::validateBoundedString($type, 'Job type');
        return $type;
    }

    /**
     * @param array<string, mixed> $job
     * @return non-empty-string
     */
    private static function jobQueue(array $job): string
    {
        $queue = $job['queue'] ?? 'default';
        if (!is_string($queue)) {
            throw new \InvalidArgumentException('Job queue must be a non-empty string');
        }
        if ($queue === '' || trim($queue) === '') {
            throw new \InvalidArgumentException('Job queue must be a non-empty string');
        }
        self::validateBoundedString($queue, 'Queue');
        return $queue;
    }

    /**
     * @param array<string, mixed> $job
     * @return array<mixed, mixed>
     */
    private static function jobPayload(array $job): array
    {
        $payload = $job['payload'] ?? null;
        if (!is_array($payload)) {
            throw new \InvalidArgumentException('Job payload must be an array');
        }
        return $payload;
    }

    /** @param array<string, mixed> $job */
    private static function jobMaxAttempts(array $job): int
    {
        $maxAttempts = $job['maxAttempts'] ?? 3;
        if (!is_int($maxAttempts)) {
            throw new \InvalidArgumentException('Maximum attempts must be an integer');
        }
        return self::validateMaxAttempts($maxAttempts);
    }

    /**
     * @param array<string, mixed> $job
     * @return non-empty-string|null
     */
    private static function jobRequestId(array $job): ?string
    {
        $requestId = $job['requestId'] ?? null;
        if ($requestId === null) {
            return null;
        }
        if (!is_string($requestId)) {
            throw new \InvalidArgumentException('Request ID must be a non-empty string when provided');
        }
        if ($requestId === '' || trim($requestId) === '') {
            throw new \InvalidArgumentException('Request ID must be a non-empty string when provided');
        }
        self::validateBoundedString($requestId, 'Request ID');
        return $requestId;
    }

    /** @param array<string, mixed> $job */
    private static function jobAvailableAt(array $job, ClockInterface $clock): string
    {
        $availableAt = $job['availableAt'] ?? null;
        if ($availableAt === null) {
            return $clock->now();
        }
        return self::normalizeAvailableAt($availableAt, $clock);
    }
}
