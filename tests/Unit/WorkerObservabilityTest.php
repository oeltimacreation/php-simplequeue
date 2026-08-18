<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Unit;

use Oeltima\SimpleQueue\Contract\JobHandlerInterface;
use Oeltima\SimpleQueue\Contract\QueueDriverInterface;
use Oeltima\SimpleQueue\Contract\WorkerEventInterface;
use Oeltima\SimpleQueue\Internal\WorkerLoopFailureHandler;
use Oeltima\SimpleQueue\Internal\WorkerPolicy;
use Oeltima\SimpleQueue\Tests\Support\ClaimedJobFactory;
use Oeltima\SimpleQueue\Tests\Support\JobDataFactory;
use Oeltima\SimpleQueue\Tests\Support\WorkerEventFixtures;
use Oeltima\SimpleQueue\Tests\Support\WorkerTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;

final class WorkerObservabilityTest extends WorkerTestCase
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

    public function testWorkerEventListenerEmitsEvents(): void
    {
        $handler = new class implements JobHandlerInterface {
            public function handle(int $jobId, array $payload, ?callable $progressCallback = null): mixed
            {
                return ['ok' => true];
            }
        };
        $this->registry->register('test.job', get_class($handler));

        $jobData = JobDataFactory::running(['id' => 123, 'type' => 'test.job']);

        $driver = $this->createMock(QueueDriverInterface::class);
        $driver->expects($this->once())
            ->method('dequeue')
            ->willReturn(123);

        $this->storage->expects($this->once())
            ->method('claimById')
            ->willReturn(ClaimedJobFactory::create($jobData, 'worker-1', 'token-123'));

        $this->storage->expects($this->once())
            ->method('markCompleted')
            ->willReturn(true);

        $events = [];
        $listener = function (string $event, array $data) use (&$events): void {
            $events[] = [$event, $data];
        };

        $worker = $this->createWorkerWithDriver($driver, [
            'event_listener' => $listener,
        ]);
        $worker->processOne();

        $this->assertCount(2, $events);
        $this->assertEquals('claimed', $events[0][0]);
        $this->assertEquals(123, $events[0][1]['job_id']);
        $this->assertArrayHasKey('acquire_latency_ms', $events[0][1]);

        $this->assertEquals('completed', $events[1][0]);
        $this->assertEquals(123, $events[1][1]['job_id']);
        $this->assertArrayHasKey('duration_ms', $events[1][1]);
    }

    public function testWorkerNormalizesCallableEventListenersToClosures(): void
    {
        $listener = new class {
            /** @param array<string, mixed> $data */
            public function handle(string $event, array $data): void
            {
            }
        };
        $worker = $this->createWorkerWithDriver(
            $this->createMock(QueueDriverInterface::class),
            ['event_listener' => [$listener, 'handle']]
        );

        $reflection = new \ReflectionClass($worker);
        $property = $reflection->getProperty('eventListener');
        self::assertInstanceOf(\Closure::class, $property->getValue($worker));

        $worker->setEventListener([$listener, 'handle']);

        self::assertInstanceOf(\Closure::class, $property->getValue($worker));
    }
}
