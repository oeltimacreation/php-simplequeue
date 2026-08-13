<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Support;

use Oeltima\SimpleQueue\Contract\InfrastructureErrorEvent;
use Oeltima\SimpleQueue\Contract\InfrastructureFailureEvent;
use Oeltima\SimpleQueue\Contract\JobClaimedEvent;
use Oeltima\SimpleQueue\Contract\JobCompletedEvent;
use Oeltima\SimpleQueue\Contract\JobFailedEvent;
use Oeltima\SimpleQueue\Contract\JobLostOwnershipEvent;
use Oeltima\SimpleQueue\Contract\JobRetriedEvent;
use Oeltima\SimpleQueue\Contract\WorkerBackoffEvent;

final class WorkerEventFixtures
{
    /**
     * @return iterable<string, array{class-string<\Oeltima\SimpleQueue\Contract\WorkerEventInterface>, array<string, mixed>, non-empty-string}>
     */
    public static function cases(): iterable
    {
        yield 'claimed' => [
            JobClaimedEvent::class,
            ['job_id' => 11, 'type' => 'contract.job', 'acquire_latency_ms' => 1.5],
            'claimed',
        ];
        yield 'completed' => [
            JobCompletedEvent::class,
            ['job_id' => 11, 'type' => 'contract.job', 'duration_ms' => 2.5],
            'completed',
        ];
        yield 'retried' => [
            JobRetriedEvent::class,
            [
                'job_id' => 11,
                'type' => 'contract.job',
                'duration_ms' => 2.5,
                'attempts' => 2,
                'error' => 'temporary',
            ],
            'retried',
        ];
        yield 'failed' => [
            JobFailedEvent::class,
            ['job_id' => 11, 'type' => 'contract.job', 'duration_ms' => 2.5, 'error' => 'permanent'],
            'failed',
        ];
        yield 'lost ownership' => [
            JobLostOwnershipEvent::class,
            ['job_id' => 11, 'type' => 'contract.job', 'context' => 'complete'],
            'lost_ownership',
        ];
        yield 'infrastructure failure' => [
            InfrastructureFailureEvent::class,
            ['job_id' => 11, 'context' => 'heartbeat'],
            'infrastructure_failure',
        ];
        yield 'infrastructure error' => [
            InfrastructureErrorEvent::class,
            ['error' => 'connection lost', 'exception_class' => \RuntimeException::class],
            'infra_error',
        ];
        yield 'backoff' => [
            WorkerBackoffEvent::class,
            ['error' => 'connection lost', 'backoff_seconds' => 1.5],
            'backoff',
        ];
    }
}
