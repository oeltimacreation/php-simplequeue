<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Internal;

use Oeltima\SimpleQueue\Contract\JobData;
use Oeltima\SimpleQueue\Contract\JobStatus;
use Oeltima\SimpleQueue\Exception\QueueException;
use Oeltima\SimpleQueue\Exception\SerializationException;

/**
 * Converts untrusted storage rows into trusted job data.
 *
 * @internal
 * @phpstan-type StorageRowShape array{
 *     id?: int|string|null,
 *     queue?: string|null,
 *     type?: string|null,
 *     status?: string|JobStatus|null,
 *     payload?: string|array<string, mixed>|null,
 *     attempts?: int|string|null,
 *     max_attempts?: int|string|null,
 *     available_at?: string|\DateTimeInterface|int|null,
 *     started_at?: string|\DateTimeInterface|int|null,
 *     completed_at?: string|\DateTimeInterface|int|null,
 *     locked_by?: string|null,
 *     locked_at?: string|\DateTimeInterface|int|null,
 *     lease_token?: string|null,
 *     error_message?: string|null,
 *     error_trace?: string|null,
 *     progress?: int|string|null,
 *     progress_message?: string|null,
 *     result?: mixed,
 *     request_id?: string|null,
 *     created_at?: string|\DateTimeInterface|int|null,
 *     updated_at?: string|\DateTimeInterface|int|null
 * }
 */
final class JobDataHydrator
{
    /** @var array<string, mixed> */
    private const ROW_DEFAULTS = [
        'id' => 0,
        'queue' => 'default',
        'type' => '',
        'status' => 'pending',
        'payload' => '[]',
        'attempts' => 0,
        'max_attempts' => 3,
        'available_at' => null,
        'started_at' => null,
        'completed_at' => null,
        'locked_by' => null,
        'locked_at' => null,
        'lease_token' => null,
        'error_message' => null,
        'error_trace' => null,
        'progress' => null,
        'progress_message' => null,
        'result' => null,
        'request_id' => null,
        'created_at' => null,
        'updated_at' => null,
    ];

    /**
     * Hydrate job data from a database row or storage object.
     *
     * This permissive path is retained for v1 backward compatibility with
     * public JobData::fromRaw() callers. Built-in storage reads use
     * hydrateStrict() instead.
     *
     * @param StorageRowShape|array<string, mixed>|object $data Raw storage data
     * @return JobData Normalized job data
     */
    public static function hydrate(array|object $data): JobData
    {
        if (is_object($data)) {
            $data = (array) $data;
        }

        $row = self::ROW_DEFAULTS;
        foreach ($data as $key => $value) {
            if (is_string($key) && $value !== null) {
                $row[$key] = $value;
            }
        }
        $statusRaw = $row['status'];
        $status = $statusRaw instanceof JobStatus ? $statusRaw : JobStatus::from($statusRaw);

        return new JobData(
            id: (int) $row['id'],
            queue: (string) $row['queue'],
            type: (string) $row['type'],
            status: $status,
            payload: self::payload($row['payload']),
            attempts: (int) $row['attempts'],
            maxAttempts: (int) $row['max_attempts'],
            availableAt: $row['available_at'],
            startedAt: $row['started_at'],
            completedAt: $row['completed_at'],
            lockedBy: $row['locked_by'],
            lockedAt: $row['locked_at'],
            leaseToken: $row['lease_token'],
            errorMessage: $row['error_message'],
            errorTrace: $row['error_trace'],
            progress: $row['progress'] === null ? null : (int) $row['progress'],
            progressMessage: $row['progress_message'],
            result: self::result($row['result']),
            requestId: $row['request_id'],
            createdAt: $row['created_at'],
            updatedAt: $row['updated_at'],
        );
    }

    /** @return array<string, mixed> */
    private static function payload(mixed $payload): array
    {
        if (is_string($payload)) {
            $payload = self::decodePayload($payload);
        }
        if (!is_array($payload)) {
            throw new SerializationException('Stored job payload must decode to an object');
        }
        foreach ($payload as $key => $value) {
            if (!is_string($key)) {
                throw new SerializationException('Stored job payload must decode to an object');
            }
        }
        return $payload;
    }

    /** @return array<mixed> */
    private static function decodePayload(string $payload): array
    {
        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new SerializationException('Stored job payload contains invalid JSON', 0, $exception);
        }
        if (!is_array($decoded)) {
            throw new SerializationException('Stored job payload must decode to an object');
        }
        return $decoded;
    }

    private static function result(mixed $result): mixed
    {
        if (is_string($result) && $result !== '') {
            try {
                return json_decode($result, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                throw new SerializationException('Stored job result contains invalid JSON', 0, $exception);
            }
        }
        return $result;
    }

    /**
     * Strictly hydrate a durable row, requiring all persisted fields.
     *
     * Built-in storage reads use this path so corrupt rows fail loudly with
     * the job ID and field instead of becoming plausible jobs (ID 0, empty type).
     *
     * @param array<string, mixed> $data Durable row data
     * @return JobData Strictly validated job data
     */
    public static function hydrateStrict(array $data): JobData
    {
        $id = self::strictId($data);
        $queue = self::strictNonEmptyString($data, 'queue', $id);
        $type = self::strictNonEmptyString($data, 'type', $id);
        $status = self::strictStatus($data, $id);
        self::requirePayload($data, $id);
        $attempts = self::strictAttempts($data, $id);
        $maxAttempts = self::strictMaxAttempts($data, $id);
        self::strictTimestamps($data, $id);
        self::strictProgress($data, $id);

        $normalized = $data;
        $normalized['id'] = $id;
        $normalized['queue'] = $queue;
        $normalized['type'] = $type;
        $normalized['status'] = $status->value;
        $normalized['attempts'] = $attempts;
        $normalized['max_attempts'] = $maxAttempts;

        return self::hydrate($normalized);
    }

    /**
     * Require a positive job ID.
     *
     * @param array<string, mixed> $data Durable row
     * @return int Positive job ID
     */
    private static function strictId(array $data): int
    {
        $raw = $data['id'] ?? null;
        $id = is_int($raw) ? $raw : (is_numeric($raw) ? (int) $raw : 0);
        if ($id < 1) {
            $label = is_scalar($raw) ? (string) $raw : 'unknown';
            throw new QueueException(sprintf('Stored job #%s has invalid field "id"', $label));
        }
        return $id;
    }

    /**
     * Require a non-empty string field.
     *
     * @param array<string, mixed> $data Durable row
     * @param string $field Field name
     * @param int $id Job ID for errors
     * @return string Validated value
     */
    private static function strictNonEmptyString(array $data, string $field, int $id): string
    {
        $value = $data[$field] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new QueueException(sprintf('Stored job #%d has invalid field "%s"', $id, $field));
        }
        return $value;
    }

    /**
     * Require a valid status.
     *
     * @param array<string, mixed> $data Durable row
     * @param int $id Job ID for errors
     * @return JobStatus Validated status
     */
    private static function strictStatus(array $data, int $id): JobStatus
    {
        $raw = $data['status'] ?? null;
        try {
            if ($raw instanceof JobStatus) {
                return $raw;
            }
            if (is_string($raw)) {
                return JobStatus::from($raw);
            }
            throw new \ValueError('Invalid status');
        } catch (\ValueError $exception) {
            throw new QueueException(sprintf('Stored job #%d has invalid field "status"', $id), 0, $exception);
        }
    }

    /**
     * Require a payload key.
     *
     * @param array<string, mixed> $data Durable row
     * @param int $id Job ID for errors
     */
    private static function requirePayload(array $data, int $id): void
    {
        if (!array_key_exists('payload', $data) || $data['payload'] === null) {
            throw new QueueException(sprintf('Stored job #%d has invalid field "payload"', $id));
        }
    }

    /**
     * Require non-negative attempts.
     *
     * @param array<string, mixed> $data Durable row
     * @param int $id Job ID for errors
     * @return int Validated attempts
     */
    private static function strictAttempts(array $data, int $id): int
    {
        $raw = $data['attempts'] ?? null;
        $attempts = is_int($raw) ? $raw : (is_numeric($raw) ? (int) $raw : -1);
        if ($attempts < 0) {
            throw new QueueException(sprintf('Stored job #%d has invalid field "attempts"', $id));
        }
        return $attempts;
    }

    /**
     * Require positive max attempts.
     *
     * @param array<string, mixed> $data Durable row
     * @param int $id Job ID for errors
     * @return int Validated max attempts
     */
    private static function strictMaxAttempts(array $data, int $id): int
    {
        $raw = $data['max_attempts'] ?? null;
        $max = is_int($raw) ? $raw : (is_numeric($raw) ? (int) $raw : 0);
        if ($max < 1) {
            throw new QueueException(sprintf('Stored job #%d has invalid field "max_attempts"', $id));
        }
        return $max;
    }

    /**
     * Validate timestamp nullability invariants.
     *
     * @param array<string, mixed> $data Durable row
     * @param int $id Job ID for errors
     */
    private static function strictTimestamps(array $data, int $id): void
    {
        foreach (['available_at', 'created_at', 'updated_at'] as $field) {
            if (!array_key_exists($field, $data)) {
                throw new QueueException(sprintf('Stored job #%d has invalid field "%s"', $id, $field));
            }
            self::strictTimestampValue($data[$field], $field, $id);
        }
        $optional = ['started_at', 'completed_at', 'locked_by', 'locked_at', 'lease_token'];
        $optional = array_merge($optional, ['error_message', 'error_trace', 'progress_message', 'request_id']);
        foreach ($optional as $field) {
            if (array_key_exists($field, $data)) {
                self::strictTimestampValue($data[$field], $field, $id);
            }
        }
    }

    /**
     * Validate one timestamp-like value.
     *
     * @param mixed $value Raw value
     * @param string $field Field name
     * @param int $id Job ID for errors
     */
    private static function strictTimestampValue(mixed $value, string $field, int $id): void
    {
        if ($value !== null && !is_string($value) && !$value instanceof \DateTimeInterface && !is_int($value)) {
            throw new QueueException(sprintf('Stored job #%d has invalid field "%s"', $id, $field));
        }
    }

    /**
     * Validate progress nullability.
     *
     * @param array<string, mixed> $data Durable row
     * @param int $id Job ID for errors
     */
    private static function strictProgress(array $data, int $id): void
    {
        if (!array_key_exists('progress', $data)) {
            return;
        }
        $progress = $data['progress'];
        if ($progress !== null && !is_int($progress) && !is_numeric($progress)) {
            throw new QueueException(sprintf('Stored job #%d has invalid field "progress"', $id));
        }
    }
}
