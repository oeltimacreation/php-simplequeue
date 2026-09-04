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
    /** @var list<string> */
    private const DURABLE_FIELDS = [
        'id',
        'queue',
        'type',
        'status',
        'payload',
        'attempts',
        'max_attempts',
        'available_at',
        'started_at',
        'completed_at',
        'locked_by',
        'locked_at',
        'lease_token',
        'error_message',
        'error_trace',
        'progress',
        'progress_message',
        'result',
        'request_id',
        'created_at',
        'updated_at',
    ];

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
        self::requireDurableFields($data, $id);
        $queue = self::strictNonEmptyString($data, 'queue', $id, 255);
        $type = self::strictNonEmptyString($data, 'type', $id, 255);
        $status = self::strictStatus($data, $id);
        self::requirePayload($data, $id);
        $attempts = self::strictAttempts($data, $id);
        $maxAttempts = self::strictMaxAttempts($data, $id);
        if ($attempts > $maxAttempts) {
            self::invalid($id, 'attempts');
        }
        self::strictTimestamps($data, $id);
        self::strictNullableStrings($data, $id);
        self::strictResult($data, $id);
        $progress = self::strictProgress($data, $id);
        self::strictStateNullability($data, $status, $id);

        $normalized = $data;
        $normalized['id'] = $id;
        $normalized['queue'] = $queue;
        $normalized['type'] = $type;
        $normalized['status'] = $status->value;
        $normalized['attempts'] = $attempts;
        $normalized['max_attempts'] = $maxAttempts;
        $normalized['progress'] = $progress;

        return self::hydrate($normalized);
    }

    /**
     * Require every column persisted by the built-in storage schema.
     *
     * @param array<string, mixed> $data Durable row
     * @param int $id Job ID for errors
     */
    private static function requireDurableFields(array $data, int $id): void
    {
        foreach (self::DURABLE_FIELDS as $field) {
            if (!array_key_exists($field, $data)) {
                self::invalid($id, $field);
            }
        }
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
        $id = self::canonicalInteger($raw);
        if ($id === null || $id < 1) {
            $label = is_scalar($raw) ? (string) $raw : 'unknown';
            self::invalid($label, 'id');
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
    private static function strictNonEmptyString(array $data, string $field, int $id, int $maxBytes): string
    {
        $value = $data[$field] ?? null;
        if (!is_string($value) || trim($value) === '' || strlen($value) > $maxBytes) {
            self::invalid($id, $field);
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
        if (!is_string($data['payload'] ?? null)) {
            self::invalid($id, 'payload');
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
        $attempts = self::canonicalInteger($raw);
        if ($attempts === null || $attempts < 0) {
            self::invalid($id, 'attempts');
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
        $max = self::canonicalInteger($raw);
        if ($max === null || $max < 1) {
            self::invalid($id, 'max_attempts');
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
            $value = $data[$field];
            if (!is_string($value) || trim($value) === '') {
                self::invalid($id, $field);
            }
        }
        foreach (['started_at', 'completed_at', 'locked_at'] as $field) {
            $value = $data[$field];
            if ($value !== null && (!is_string($value) || trim($value) === '')) {
                self::invalid($id, $field);
            }
        }
    }

    /**
     * Validate nullable persisted string columns and their schema bounds.
     *
     * @param array<string, mixed> $data Durable row
     * @param int $id Job ID for errors
     */
    private static function strictNullableStrings(array $data, int $id): void
    {
        foreach (['error_message', 'error_trace'] as $field) {
            if ($data[$field] !== null && !is_string($data[$field])) {
                self::invalid($id, $field);
            }
        }
        foreach (['locked_by', 'progress_message', 'request_id'] as $field) {
            $value = $data[$field];
            if ($value !== null && (!is_string($value) || strlen($value) > 255)) {
                self::invalid($id, $field);
            }
        }
        foreach (['locked_by', 'request_id'] as $field) {
            $value = $data[$field];
            if (is_string($value) && trim($value) === '') {
                self::invalid($id, $field);
            }
        }
        $lease = $data['lease_token'];
        if ($lease !== null && (!is_string($lease) || preg_match('/^[a-f0-9]{64}$/D', $lease) !== 1)) {
            self::invalid($id, 'lease_token');
        }
    }

    /**
     * Require a nullable JSON result representation.
     *
     * @param array<string, mixed> $data Durable row
     * @param int $id Job ID for errors
     */
    private static function strictResult(array $data, int $id): void
    {
        $result = $data['result'];
        if ($result !== null && !is_string($result)) {
            self::invalid($id, 'result');
        }
        if ($result === '') {
            throw new SerializationException('Stored job result contains invalid JSON');
        }
    }

    /**
     * Validate progress nullability.
     *
     * @param array<string, mixed> $data Durable row
     * @param int $id Job ID for errors
     */
    private static function strictProgress(array $data, int $id): ?int
    {
        $progress = $data['progress'];
        if ($progress === null) {
            return null;
        }
        $normalized = self::canonicalInteger($progress);
        if ($normalized === null || $normalized < 0 || $normalized > 100) {
            self::invalid($id, 'progress');
        }
        return $normalized;
    }

    /**
     * Validate ownership and terminal timestamp invariants.
     *
     * @param array<string, mixed> $data Durable row
     * @param JobStatus $status Normalized status
     * @param int $id Job ID for errors
     */
    private static function strictStateNullability(array $data, JobStatus $status, int $id): void
    {
        $ownershipFields = ['locked_by', 'locked_at', 'lease_token'];
        if ($status === JobStatus::Running) {
            foreach ($ownershipFields as $field) {
                if ($data[$field] === null) {
                    self::invalid($id, $field);
                }
            }
            if ($data['started_at'] === null) {
                self::invalid($id, 'started_at');
            }
        } else {
            foreach ($ownershipFields as $field) {
                if ($data[$field] !== null) {
                    self::invalid($id, $field);
                }
            }
        }
        if ($status === JobStatus::Pending && $data['completed_at'] !== null) {
            self::invalid($id, 'completed_at');
        }
        if ($status->isTerminal() && $data['completed_at'] === null) {
            self::invalid($id, 'completed_at');
        }
    }

    private static function canonicalInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (!is_string($value) || preg_match('/^(0|[1-9][0-9]*)$/D', $value) !== 1) {
            return null;
        }
        if (
            strlen($value) > strlen((string) PHP_INT_MAX)
            || (strlen($value) === strlen((string) PHP_INT_MAX) && $value > (string) PHP_INT_MAX)
        ) {
            return null;
        }
        return (int) $value;
    }

    private static function invalid(int|string $id, string $field): never
    {
        throw new QueueException(sprintf('Stored job #%s has invalid field "%s"', (string) $id, $field));
    }
}
