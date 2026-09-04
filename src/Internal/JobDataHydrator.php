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
 * @phpstan-type StrictRow array{data: array<string, mixed>, id: int}
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
        $row = ['data' => $data, 'id' => $id];
        self::requireDurableFields($row);
        $queue = self::strictNonEmptyString($row, 'queue', 255);
        $type = self::strictNonEmptyString($row, 'type', 255);
        $status = self::strictStatus($row);
        self::requirePayload($row);
        $attempts = self::strictAttempts($row);
        $maxAttempts = self::strictMaxAttempts($row);
        if ($attempts > $maxAttempts) {
            self::invalid($id, 'attempts');
        }
        self::strictTimestamps($row);
        self::strictNullableStrings($row);
        self::strictResult($row);
        $progress = self::strictProgress($row);
        self::strictStateNullability($row, $status);

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
     * @param StrictRow $row Durable row and job ID
     */
    private static function requireDurableFields(array $row): void
    {
        foreach (self::DURABLE_FIELDS as $field) {
            if (!array_key_exists($field, $row['data'])) {
                self::invalid($row['id'], $field);
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
     * @param StrictRow $row Durable row and job ID
     * @param string $field Field name
     * @return string Validated value
     */
    private static function strictNonEmptyString(array $row, string $field, int $maxBytes): string
    {
        $value = $row['data'][$field] ?? null;
        if (!is_string($value)) {
            self::invalid($row['id'], $field);
        }
        if (trim($value) === '') {
            self::invalid($row['id'], $field);
        }
        if (strlen($value) > $maxBytes) {
            self::invalid($row['id'], $field);
        }
        return $value;
    }

    /**
     * Require a valid status.
     *
     * @param StrictRow $row Durable row and job ID
     * @return JobStatus Validated status
     */
    private static function strictStatus(array $row): JobStatus
    {
        $raw = $row['data']['status'] ?? null;
        try {
            if ($raw instanceof JobStatus) {
                return $raw;
            }
            if (is_string($raw)) {
                return JobStatus::from($raw);
            }
            throw new \ValueError('Invalid status');
        } catch (\ValueError $exception) {
            throw new QueueException(sprintf('Stored job #%d has invalid field "status"', $row['id']), 0, $exception);
        }
    }

    /**
     * Require a payload key.
     *
     * @param StrictRow $row Durable row and job ID
     */
    private static function requirePayload(array $row): void
    {
        if (!is_string($row['data']['payload'] ?? null)) {
            self::invalid($row['id'], 'payload');
        }
    }

    /**
     * Require non-negative attempts.
     *
     * @param StrictRow $row Durable row and job ID
     * @return int Validated attempts
     */
    private static function strictAttempts(array $row): int
    {
        $raw = $row['data']['attempts'] ?? null;
        $attempts = self::canonicalInteger($raw);
        if ($attempts === null || $attempts < 0) {
            self::invalid($row['id'], 'attempts');
        }
        return $attempts;
    }

    /**
     * Require positive max attempts.
     *
     * @param StrictRow $row Durable row and job ID
     * @return int Validated max attempts
     */
    private static function strictMaxAttempts(array $row): int
    {
        $raw = $row['data']['max_attempts'] ?? null;
        $max = self::canonicalInteger($raw);
        if ($max === null || $max < 1) {
            self::invalid($row['id'], 'max_attempts');
        }
        return $max;
    }

    /**
     * Validate timestamp nullability invariants.
     *
     * @param StrictRow $row Durable row and job ID
     */
    private static function strictTimestamps(array $row): void
    {
        foreach (['available_at', 'created_at', 'updated_at'] as $field) {
            self::requireString($row, $field, ['nullable' => false, 'nonEmpty' => true, 'maxLength' => null]);
        }
        foreach (['started_at', 'completed_at', 'locked_at'] as $field) {
            self::requireString($row, $field, ['nullable' => true, 'nonEmpty' => true, 'maxLength' => null]);
        }
    }

    /**
     * Validate nullable persisted string columns and their schema bounds.
     *
     * @param StrictRow $row Durable row and job ID
     */
    private static function strictNullableStrings(array $row): void
    {
        foreach (['error_message', 'error_trace'] as $field) {
            self::requireString($row, $field, ['nullable' => true, 'nonEmpty' => false, 'maxLength' => null]);
        }
        foreach (['locked_by', 'request_id'] as $field) {
            self::requireString($row, $field, ['nullable' => true, 'nonEmpty' => true, 'maxLength' => 255]);
        }
        self::requireString(
            $row,
            'progress_message',
            ['nullable' => true, 'nonEmpty' => false, 'maxLength' => 255]
        );
        self::requireLeaseToken($row);
    }

    /**
     * @param StrictRow $row
     * @param array{nullable: bool, nonEmpty: bool, maxLength: int|null} $rules
     */
    private static function requireString(array $row, string $field, array $rules): void
    {
        $value = $row['data'][$field];
        if ($value === null) {
            if ($rules['nullable']) {
                return;
            }
            self::invalid($row['id'], $field);
        }
        if (!is_string($value)) {
            self::invalid($row['id'], $field);
        }
        self::requireConfiguredNonEmptyString([
            'row' => $row,
            'field' => $field,
            'value' => $value,
            'required' => $rules['nonEmpty'],
        ]);
        self::requireConfiguredStringLength([
            'row' => $row,
            'field' => $field,
            'value' => $value,
            'maxLength' => $rules['maxLength'],
        ]);
    }

    /** @param array{row: StrictRow, field: string, value: string, required: bool} $context */
    private static function requireConfiguredNonEmptyString(array $context): void
    {
        if (!$context['required']) {
            return;
        }
        if (trim($context['value']) === '') {
            self::invalid($context['row']['id'], $context['field']);
        }
    }

    /** @param array{row: StrictRow, field: string, value: string, maxLength: int|null} $context */
    private static function requireConfiguredStringLength(array $context): void
    {
        if ($context['maxLength'] === null) {
            return;
        }
        if (strlen($context['value']) > $context['maxLength']) {
            self::invalid($context['row']['id'], $context['field']);
        }
    }

    /** @param StrictRow $row */
    private static function requireLeaseToken(array $row): void
    {
        $lease = $row['data']['lease_token'];
        if ($lease === null) {
            return;
        }
        if (!is_string($lease) || preg_match('/^[a-f0-9]{64}$/D', $lease) !== 1) {
            self::invalid($row['id'], 'lease_token');
        }
    }

    /**
     * Require a nullable JSON result representation.
     *
     * @param StrictRow $row Durable row and job ID
     */
    private static function strictResult(array $row): void
    {
        $result = $row['data']['result'];
        if ($result !== null && !is_string($result)) {
            self::invalid($row['id'], 'result');
        }
        if ($result === '') {
            throw new SerializationException('Stored job result contains invalid JSON');
        }
    }

    /**
     * Validate progress nullability.
     *
     * @param StrictRow $row Durable row and job ID
     */
    private static function strictProgress(array $row): ?int
    {
        $progress = $row['data']['progress'];
        if ($progress === null) {
            return null;
        }
        $normalized = self::canonicalInteger($progress);
        if ($normalized === null) {
            self::invalid($row['id'], 'progress');
        }
        if ($normalized < 0 || $normalized > 100) {
            self::invalid($row['id'], 'progress');
        }
        return $normalized;
    }

    /**
     * Validate ownership and terminal timestamp invariants.
     *
     * @param StrictRow $row Durable row and job ID
     * @param JobStatus $status Normalized status
     */
    private static function strictStateNullability(array $row, JobStatus $status): void
    {
        if ($status === JobStatus::Running) {
            self::requireRunningState($row);
        } else {
            self::requireUnlockedState($row);
        }
        self::requireCompletionState($row, $status);
    }

    /** @param StrictRow $row */
    private static function requireRunningState(array $row): void
    {
        foreach (['locked_by', 'locked_at', 'lease_token', 'started_at'] as $field) {
            if ($row['data'][$field] === null) {
                self::invalid($row['id'], $field);
            }
        }
    }

    /** @param StrictRow $row */
    private static function requireUnlockedState(array $row): void
    {
        foreach (['locked_by', 'locked_at', 'lease_token'] as $field) {
            if ($row['data'][$field] !== null) {
                self::invalid($row['id'], $field);
            }
        }
    }

    /** @param StrictRow $row */
    private static function requireCompletionState(array $row, JobStatus $status): void
    {
        $completedAt = $row['data']['completed_at'];
        if ($status === JobStatus::Pending && $completedAt !== null) {
            self::invalid($row['id'], 'completed_at');
        }
        if ($status->isTerminal() && $completedAt === null) {
            self::invalid($row['id'], 'completed_at');
        }
    }

    private static function canonicalInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (!is_string($value)) {
            return null;
        }
        if (preg_match('/^(0|[1-9][0-9]*)$/D', $value) !== 1) {
            return null;
        }
        if (self::integerStringOverflows($value)) {
            return null;
        }
        return (int) $value;
    }

    private static function integerStringOverflows(string $value): bool
    {
        $maximum = (string) PHP_INT_MAX;
        if (strlen($value) !== strlen($maximum)) {
            return strlen($value) > strlen($maximum);
        }
        return $value > $maximum;
    }

    private static function invalid(int|string $id, string $field): never
    {
        throw new QueueException(sprintf('Stored job #%s has invalid field "%s"', (string) $id, $field));
    }
}
