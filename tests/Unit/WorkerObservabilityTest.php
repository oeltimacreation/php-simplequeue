<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Unit;

use Oeltima\SimpleQueue\Contract\WorkerEventInterface;
use Oeltima\SimpleQueue\Internal\WorkerLoopFailureHandler;
use Oeltima\SimpleQueue\Internal\WorkerPolicy;
use Oeltima\SimpleQueue\Tests\Support\WorkerEventFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class WorkerObservabilityTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string<WorkerEventInterface>, array<string, mixed>, non-empty-string}>
     */
    public static function eventPayloads(): iterable
    {
        return WorkerEventFixtures::cases();
    }

    /**
     * @param class-string<WorkerEventInterface> $eventClass
     * @param array<string, mixed> $payload
     * @param non-empty-string $name
     */
    #[DataProvider('eventPayloads')]
    public function testTypedEventsRoundTripWithStablePayload(
        string $eventClass,
        array $payload,
        string $name
    ): void {
        $event = $eventClass::fromArray($payload);

        self::assertSame($name, $event->getName());
        self::assertSame(array_keys($payload), array_keys($event->toArray()));
        self::assertSame($payload, $event->toArray());

        foreach ($event->toArray() as $value) {
            self::assertFalse($value instanceof \Throwable);
        }
    }

    public function testInfrastructureEventContainsNoThrowableOrStackData(): void
    {
        $events = [];
        $handler = new WorkerLoopFailureHandler(new NullLogger(), new WorkerPolicy(0, 0));

        $count = $handler->handle(
            new \PDOException('Connection lost'),
            0,
            static function (WorkerEventInterface $event) use (&$events): void {
                $events[$event->getName()] = $event->toArray();
            }
        );

        self::assertSame(1, $count);
        self::assertSame([
            'error' => 'Connection lost',
            'exception_class' => \PDOException::class,
        ], $events['infra_error']);
        self::assertArrayNotHasKey('exception', $events['infra_error']);
        self::assertArrayNotHasKey('trace', $events['infra_error']);
    }
}
