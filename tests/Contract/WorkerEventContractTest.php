<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Contract;

use Oeltima\SimpleQueue\Contract\InfrastructureErrorEvent;
use Oeltima\SimpleQueue\Contract\InfrastructureFailureEvent;
use Oeltima\SimpleQueue\Contract\JobClaimedEvent;
use Oeltima\SimpleQueue\Contract\JobCompletedEvent;
use Oeltima\SimpleQueue\Contract\JobFailedEvent;
use Oeltima\SimpleQueue\Contract\JobLostOwnershipEvent;
use Oeltima\SimpleQueue\Contract\JobRetriedEvent;
use Oeltima\SimpleQueue\Contract\WorkerBackoffEvent;
use Oeltima\SimpleQueue\Contract\WorkerEventInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WorkerEventContractTest extends TestCase
{
    /**
     * @return iterable<string, array{\Closure(array<string, mixed>): WorkerEventInterface, array<string, mixed>, string}>
     */
    public static function events(): iterable
    {
        yield 'claimed' => [
            static fn (array $data): WorkerEventInterface => JobClaimedEvent::fromArray($data),
            ['job_id' => 11, 'type' => 'contract.job', 'acquire_latency_ms' => 1.5],
            'claimed',
        ];
        yield 'completed' => [
            static fn (array $data): WorkerEventInterface => JobCompletedEvent::fromArray($data),
            ['job_id' => 11, 'type' => 'contract.job', 'duration_ms' => 2.5],
            'completed',
        ];
        yield 'retried' => [
            static fn (array $data): WorkerEventInterface => JobRetriedEvent::fromArray($data),
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
            static fn (array $data): WorkerEventInterface => JobFailedEvent::fromArray($data),
            ['job_id' => 11, 'type' => 'contract.job', 'duration_ms' => 2.5, 'error' => 'permanent'],
            'failed',
        ];
        yield 'lost ownership' => [
            static fn (array $data): WorkerEventInterface => JobLostOwnershipEvent::fromArray($data),
            ['job_id' => 11, 'type' => 'contract.job', 'context' => 'complete'],
            'lost_ownership',
        ];
        yield 'infrastructure failure' => [
            static fn (array $data): WorkerEventInterface => InfrastructureFailureEvent::fromArray($data),
            ['job_id' => 11, 'context' => 'heartbeat'],
            'infrastructure_failure',
        ];
        yield 'infrastructure error' => [
            static fn (array $data): WorkerEventInterface => InfrastructureErrorEvent::fromArray($data),
            ['error' => 'connection lost', 'exception_class' => \RuntimeException::class],
            'infra_error',
        ];
        yield 'backoff' => [
            static fn (array $data): WorkerEventInterface => WorkerBackoffEvent::fromArray($data),
            ['error' => 'connection lost', 'backoff_seconds' => 1.5],
            'backoff',
        ];
    }

    /**
     * @param \Closure(array<string, mixed>): WorkerEventInterface $factory
     * @param array<string, mixed> $payload
     */
    #[DataProvider('events')]
    public function testEventContractPreservesTypedReadonlyPayload(
        \Closure $factory,
        array $payload,
        string $name
    ): void {
        $event = $factory($payload);

        self::assertTrue((new \ReflectionClass($event))->isReadOnly());
        self::assertSame($name, $event->getName());
        self::assertSame($payload, $event->toArray());
        foreach ($event->toArray() as $value) {
            self::assertFalse($value instanceof \Throwable);
        }
    }

    public function testMissingRequiredFieldIsRejectedByTypedEventFactory(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Worker event field "job_id" is required.');

        JobClaimedEvent::fromArray([
            'type' => 'contract.job',
            'acquire_latency_ms' => 1.5,
        ]);
    }
}
