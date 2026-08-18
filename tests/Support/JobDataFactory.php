<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Support;

use Oeltima\SimpleQueue\Contract\JobData;
use Oeltima\SimpleQueue\Contract\JobStatus;

final class JobDataFactory
{
    /**
     * Create running job data with explicit, type-safe overrides.
     *
     * @param array{
     *     id?: int,
     *     queue?: string,
     *     type?: string,
     *     payload?: array<string, mixed>,
     *     attempts?: int,
     *     maxAttempts?: int,
     *     availableAt?: string|null,
     *     startedAt?: string|null,
     *     completedAt?: string|null,
     *     lockedBy?: string|null,
     *     lockedAt?: string|null,
     *     leaseToken?: string|null,
     *     errorMessage?: string|null,
     *     errorTrace?: string|null,
     *     progress?: int|null,
     *     progressMessage?: string|null,
     *     result?: mixed,
     *     requestId?: string|null,
     *     createdAt?: string|null,
     *     updatedAt?: string|null
     * } $overrides
     * @return JobData Running job data
     */
    public static function running(array $overrides = []): JobData
    {
        /** @var array{
         *     id: int,
         *     queue: string,
         *     type: string,
         *     payload: array<string, mixed>,
         *     attempts: int,
         *     maxAttempts: int,
         *     availableAt: string|null,
         *     startedAt: string|null,
         *     completedAt: string|null,
         *     lockedBy: string|null,
         *     lockedAt: string|null,
         *     leaseToken: string|null,
         *     errorMessage: string|null,
         *     errorTrace: string|null,
         *     progress: int|null,
         *     progressMessage: string|null,
         *     result: mixed,
         *     requestId: string|null,
         *     createdAt: string|null,
         *     updatedAt: string|null
         * } $data */
        $data = array_replace([
            'id' => 1,
            'queue' => 'default',
            'type' => 'test_job',
            'payload' => ['key' => 'value'],
            'attempts' => 1,
            'maxAttempts' => 3,
            'availableAt' => null,
            'startedAt' => '2026-08-08 00:00:01',
            'completedAt' => null,
            'lockedBy' => 'worker-1',
            'lockedAt' => '2026-08-08 00:00:01',
            'leaseToken' => 'lease-token-1',
            'errorMessage' => null,
            'errorTrace' => null,
            'progress' => null,
            'progressMessage' => null,
            'result' => null,
            'requestId' => null,
            'createdAt' => '2026-08-08 00:00:00',
            'updatedAt' => '2026-08-08 00:00:00',
        ], $overrides);

        return new JobData(
            id: $data['id'],
            queue: $data['queue'],
            type: $data['type'],
            status: JobStatus::Running,
            payload: $data['payload'],
            result: $data['result'],
            attempts: $data['attempts'],
            availableAt: $data['availableAt'],
            maxAttempts: $data['maxAttempts'],
            startedAt: $data['startedAt'],
            lockedBy: $data['lockedBy'],
            completedAt: $data['completedAt'],
            lockedAt: $data['lockedAt'],
            leaseToken: $data['leaseToken'],
            errorMessage: $data['errorMessage'],
            errorTrace: $data['errorTrace'],
            progress: $data['progress'],
            progressMessage: $data['progressMessage'],
            requestId: $data['requestId'],
            createdAt: $data['createdAt'],
            updatedAt: $data['updatedAt'],
        );
    }
}
