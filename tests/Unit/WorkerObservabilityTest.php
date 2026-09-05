<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Unit;

use Oeltima\SimpleQueue\Contract\JobHandlerInterface;
use Oeltima\SimpleQueue\Contract\JobCompletedEvent;
use Oeltima\SimpleQueue\Contract\QueueDriverInterface;
use Oeltima\SimpleQueue\Contract\SupportsProcessingHeartbeat;
use Oeltima\SimpleQueue\Contract\WorkerEventInterface;
use Oeltima\SimpleQueue\Internal\WorkerLoopFailureHandler;
use Oeltima\SimpleQueue\Internal\WorkerPolicy;
use Oeltima\SimpleQueue\Tests\Support\ClaimedJobFactory;
use Oeltima\SimpleQueue\Tests\Support\JobDataFactory;
use Oeltima\SimpleQueue\Tests\Support\WorkerEventFixtures;
use Oeltima\SimpleQueue\Tests\Support\WorkerTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;

interface WorkerHeartbeatDriver extends QueueDriverInterface, SupportsProcessingHeartbeat
{
}

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

    public function testTypedEventRejectsInvalidFieldTypeConsistently(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Worker event field "job_id" must be a integer.');

        JobCompletedEvent::fromArray([
            'job_id' => '123',
            'type' => 'test.job',
            'duration_ms' => 1.0,
        ]);
    }

    public function testProcessingHeartbeatFailureEmitsBoundedInfrastructureEvent(): void
    {
        $handler = new class implements JobHandlerInterface {
            public function handle(int $jobId, array $payload, ?callable $progressCallback = null): mixed
            {
                if ($progressCallback !== null) {
                    $progressCallback(50, 'halfway');
                }
                return true;
            }
        };
        $this->registry->register('test.job', $handler::class);
        $job = JobDataFactory::running(['id' => 123, 'type' => 'test.job']);
        $driver = $this->createMock(WorkerHeartbeatDriver::class);
        $driver->expects($this->once())->method('dequeue')->willReturn(123);
        $driver->expects($this->once())
            ->method('heartbeatProcessing')
            ->with('default', 123)
            ->willThrowException(new \RuntimeException('Redis unavailable'));
        $driver->expects($this->once())->method('ack')->with('default', 123);
        $this->storage->expects($this->once())
            ->method('claimById')
            ->willReturn(ClaimedJobFactory::create($job, 'worker-1', 'lease'));
        $this->storage->expects($this->once())
            ->method('updateProgress')
            ->with(self::anything(), 50, 'halfway')
            ->willReturn(true);
        $this->storage->expects($this->once())->method('markCompleted')->willReturn(true);
        $events = [];
        $worker = $this->createWorkerWithDriver($driver, [
            'event_listener' => static function (string $name, array $payload) use (&$events): void {
                $events[$name] = $payload;
            },
        ]);

        self::assertTrue($worker->processOne());
        self::assertSame([
            'job_id' => 123,
            'context' => 'processing_heartbeat',
        ], $events['infrastructure_failure']);
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

        self::assertCount(2, $events);
        self::assertEquals('claimed', $events[0][0]);
        self::assertEquals(123, $events[0][1]['job_id']);
        self::assertArrayHasKey('acquire_latency_ms', $events[0][1]);

        self::assertEquals('completed', $events[1][0]);
        self::assertEquals(123, $events[1][1]['job_id']);
        self::assertArrayHasKey('duration_ms', $events[1][1]);
    }

    public function testWorkerAcceptsCallableListenersFromOptionsAndSetter(): void
    {
        $firstListener = new class {
            /** @var list<string> */
            public array $events = [];

            /** @param array<string, mixed> $data */
            public function handle(string $event, array $data): void
            {
                $this->events[] = $event;
            }
        };
        $secondListener = clone $firstListener;
        $handler = new class implements JobHandlerInterface {
            public function handle(int $jobId, array $payload, ?callable $progressCallback = null): mixed
            {
                return true;
            }
        };
        $this->registry->register('test.job', $handler::class);

        $driver = $this->createMock(QueueDriverInterface::class);
        $driver->expects($this->exactly(2))->method('dequeue')->willReturnOnConsecutiveCalls(123, 124);
        $this->storage->expects($this->exactly(2))
            ->method('claimById')
            ->willReturnCallback(static fn (int $jobId, string $workerId) => ClaimedJobFactory::create(
                JobDataFactory::running(['id' => $jobId, 'type' => 'test.job']),
                $workerId,
                'lease-' . $jobId
            ));
        $this->storage->expects($this->exactly(2))->method('markCompleted')->willReturn(true);

        $worker = $this->createWorkerWithDriver(
            $driver,
            ['event_listener' => [$firstListener, 'handle']]
        );
        self::assertTrue($worker->processOne());

        $worker->setEventListener([$secondListener, 'handle']);
        self::assertTrue($worker->processOne());

        self::assertSame(['claimed', 'completed'], $firstListener->events);
        self::assertSame(['claimed', 'completed'], $secondListener->events);
    }
}
