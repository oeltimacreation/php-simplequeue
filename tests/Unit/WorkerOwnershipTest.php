<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Unit;

use Oeltima\SimpleQueue\Exception\SerializationException;
use Oeltima\SimpleQueue\Tests\Support\JobDataFactory;
use Oeltima\SimpleQueue\Tests\Support\WorkerTestCase;

final class WorkerOwnershipTest extends WorkerTestCase
{
    public function testWorkerHandlesLostOwnershipOnJobCompletion(): void
    {
        $jobData = JobDataFactory::running(['id' => 123, 'type' => 'test.job']);
        $driver = $this->prepareProcessingScenario($jobData, $this->handlerReturning(true));

        $this->storage->expects($this->once())
            ->method('markCompleted')
            ->willReturn(false);

        $driver->expects($this->never())
            ->method('ack');

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                'Lost job ownership before completion ack',
                ['job_id' => 123]
            );

        $events = [];
        $worker = $this->createWorkerWithDriver($driver, [
            'event_listener' => static function (string $name, array $payload) use (&$events): void {
                $events[$name] = $payload;
            },
        ]);
        $worker->processOne();

        self::assertSame('complete', $events['lost_ownership']['context']);
    }

    public function testWorkerHandlesLostOwnershipOnRetryScheduling(): void
    {
        $jobData = JobDataFactory::running(['id' => 123, 'type' => 'test.job']);
        $driver = $this->prepareProcessingScenario($jobData, $this->handlerThrowing('Temporary error'));

        $this->storage->expects($this->once())
            ->method('scheduleRetry')
            ->willReturn(false);

        $driver->expects($this->never())
            ->method('nack');

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                'Lost job ownership before retry scheduling',
                ['job_id' => 123]
            );

        $events = [];
        $worker = $this->createWorkerWithDriver($driver, [
            'event_listener' => static function (string $name, array $payload) use (&$events): void {
                $events[$name] = $payload;
            },
        ]);
        $worker->processOne();

        self::assertSame('retry', $events['lost_ownership']['context']);
    }

    public function testWorkerHandlesLostOwnershipOnMarkingFailed(): void
    {
        $jobData = JobDataFactory::running(['id' => 123, 'type' => 'test.job', 'attempts' => 2, 'maxAttempts' => 3]);
        $driver = $this->prepareProcessingScenario($jobData, $this->handlerThrowing('Fatal error'));

        $this->storage->expects($this->once())
            ->method('markFailed')
            ->willReturn(false);

        $driver->expects($this->never())
            ->method('ack');

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                'Lost job ownership before marking failed',
                ['job_id' => 123]
            );

        $events = [];
        $worker = $this->createWorkerWithDriver($driver, [
            'event_listener' => static function (string $name, array $payload) use (&$events): void {
                $events[$name] = $payload;
            },
        ]);
        $worker->processOne();

        self::assertSame('fail', $events['lost_ownership']['context']);
    }

    public function testResultSerializationFailureHonorsLostOwnershipBeforeAck(): void
    {
        $jobData = JobDataFactory::running(['id' => 123, 'type' => 'test.job']);
        $driver = $this->prepareProcessingScenario($jobData, $this->handlerReturning(NAN));
        $this->storage->expects($this->once())
            ->method('markCompleted')
            ->willThrowException(new SerializationException('Unable to encode job result as JSON'));
        $this->storage->expects($this->once())->method('markFailed')->willReturn(false);
        $driver->expects($this->never())->method('ack');
        $events = [];
        $worker = $this->createWorkerWithDriver($driver, [
            'event_listener' => static function (string $name, array $payload) use (&$events): void {
                $events[$name] = $payload;
            },
        ]);

        self::assertTrue($worker->processOne());
        self::assertSame('result_serialization', $events['lost_ownership']['context']);
    }
}
