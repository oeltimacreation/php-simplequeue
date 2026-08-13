<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Contract;

use Oeltima\SimpleQueue\Contract\JobClaimedEvent;
use Oeltima\SimpleQueue\Tests\Support\WorkerEventFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WorkerEventContractTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string<\Oeltima\SimpleQueue\Contract\WorkerEventInterface>, array<string, mixed>, non-empty-string}>
     */
    public static function events(): iterable
    {
        return WorkerEventFixtures::cases();
    }

    /**
     * @param class-string<\Oeltima\SimpleQueue\Contract\WorkerEventInterface> $eventClass
     * @param array<string, mixed> $payload
     */
    #[DataProvider('events')]
    public function testEventContractPreservesTypedReadonlyPayload(
        string $eventClass,
        array $payload,
        string $name
    ): void {
        $event = $eventClass::fromArray($payload);

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
