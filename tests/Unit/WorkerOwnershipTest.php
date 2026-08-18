<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Unit;

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

        $worker = $this->createWorkerWithDriver($driver);
        $worker->processOne();
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

        $worker = $this->createWorkerWithDriver($driver);
        $worker->processOne();
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

        $worker = $this->createWorkerWithDriver($driver);
        $worker->processOne();
    }
}
