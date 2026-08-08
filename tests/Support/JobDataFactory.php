<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Support;

use Oeltima\SimpleQueue\Contract\JobData;
use Oeltima\SimpleQueue\Contract\JobStatus;

final class JobDataFactory
{
    /**
     * Create a JobData instance with sensible defaults and optional overrides.
     *
     * @param array<string, mixed> $overrides Property overrides
     * @return JobData
     */
    public static function create(array $overrides = []): JobData
    {
        $defaults = [
            'id' => 1,
            'queue' => 'default',
            'type' => 'test_job',
            'status' => JobStatus::Pending,
            'payload' => ['key' => 'value'],
            'attempts' => 0,
            'maxAttempts' => 3,
            'availableAt' => null,
            'startedAt' => null,
            'completedAt' => null,
            'lockedBy' => null,
            'lockedAt' => null,
            'leaseToken' => null,
            'errorMessage' => null,
            'errorTrace' => null,
            'progress' => null,
            'progressMessage' => null,
            'result' => null,
            'requestId' => null,
            'createdAt' => '2026-08-08 00:00:00',
            'updatedAt' => '2026-08-08 00:00:00',
        ];

        /** @var array<string, mixed> $data */
        $data = array_merge($defaults, $overrides);

        $status = $data['status'];
        if (!$status instanceof JobStatus) {
            $status = JobStatus::from((string) $status);
        }

        /** @var array<string, mixed> $payload */
        $payload = is_array($data['payload']) ? $data['payload'] : [];

        $id = is_int($data['id']) ? $data['id'] : (is_numeric($data['id']) ? (int) $data['id'] : 1);
        $attempts = is_int($data['attempts']) ? $data['attempts'] : (is_numeric($data['attempts']) ? (int) $data['attempts'] : 0);
        $maxAttempts = is_int($data['maxAttempts']) ? $data['maxAttempts'] : (is_numeric($data['maxAttempts']) ? (int) $data['maxAttempts'] : 3);
        $progress = is_int($data['progress']) ? $data['progress'] : (is_numeric($data['progress']) ? (int) $data['progress'] : null);

        return new JobData(
            id: $id,
            queue: (string) $data['queue'],
            type: (string) $data['type'],
            status: $status,
            payload: $payload,
            attempts: $attempts,
            maxAttempts: $maxAttempts,
            availableAt: $data['availableAt'] !== null ? (string) $data['availableAt'] : null,
            startedAt: $data['startedAt'] !== null ? (string) $data['startedAt'] : null,
            completedAt: $data['completedAt'] !== null ? (string) $data['completedAt'] : null,
            lockedBy: $data['lockedBy'] !== null ? (string) $data['lockedBy'] : null,
            lockedAt: $data['lockedAt'] !== null ? (string) $data['lockedAt'] : null,
            leaseToken: $data['leaseToken'] !== null ? (string) $data['leaseToken'] : null,
            errorMessage: $data['errorMessage'] !== null ? (string) $data['errorMessage'] : null,
            errorTrace: $data['errorTrace'] !== null ? (string) $data['errorTrace'] : null,
            progress: $progress,
            progressMessage: $data['progressMessage'] !== null ? (string) $data['progressMessage'] : null,
            result: $data['result'],
            requestId: $data['requestId'] !== null ? (string) $data['requestId'] : null,
            createdAt: $data['createdAt'] !== null ? (string) $data['createdAt'] : null,
            updatedAt: $data['updatedAt'] !== null ? (string) $data['updatedAt'] : null,
        );
    }

    /**
     * Create a pending job.
     *
     * @param array<string, mixed> $overrides
     * @return JobData
     */
    public static function pending(array $overrides = []): JobData
    {
        return self::create(array_merge(['status' => JobStatus::Pending], $overrides));
    }

    /**
     * Create a running job.
     *
     * @param array<string, mixed> $overrides
     * @return JobData
     */
    public static function running(array $overrides = []): JobData
    {
        return self::create(array_merge([
            'status' => JobStatus::Running,
            'attempts' => 1,
            'startedAt' => '2026-08-08 00:00:01',
            'lockedBy' => 'worker-1',
            'lockedAt' => '2026-08-08 00:00:01',
            'leaseToken' => 'lease-token-1',
        ], $overrides));
    }

    /**
     * Create a completed job.
     *
     * @param array<string, mixed> $overrides
     * @return JobData
     */
    public static function completed(array $overrides = []): JobData
    {
        return self::create(array_merge([
            'status' => JobStatus::Completed,
            'attempts' => 1,
            'completedAt' => '2026-08-08 00:00:02',
            'result' => ['success' => true],
        ], $overrides));
    }

    /**
     * Create a failed job.
     *
     * @param array<string, mixed> $overrides
     * @return JobData
     */
    public static function failed(array $overrides = []): JobData
    {
        return self::create(array_merge([
            'status' => JobStatus::Failed,
            'attempts' => 3,
            'maxAttempts' => 3,
            'errorMessage' => 'Job failed',
            'errorTrace' => 'Trace...',
        ], $overrides));
    }
}
