<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Internal;

use Oeltima\SimpleQueue\Contract\JobData;
use Oeltima\SimpleQueue\Contract\JobStatus;
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
}
