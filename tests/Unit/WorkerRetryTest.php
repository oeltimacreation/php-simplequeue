<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Unit;

use Oeltima\SimpleQueue\Tests\Support\JobDataFactory;
use Oeltima\SimpleQueue\Tests\Support\WorkerTestCase;

final class WorkerRetryTest extends WorkerTestCase
{
    public function testNackPassesDelayToDriver(): void
    {
        $jobData = JobDataFactory::running(['id' => 789, 'type' => 'test.job', 'attempts' => 0, 'maxAttempts' => 3]);
        $driver = $this->prepareProcessingScenario($jobData, $this->handlerThrowing('Job failed'));

        $this->storage->expects($this->once())
            ->method('scheduleRetry')
            ->willReturn(true);

        $driver->expects($this->once())
            ->method('nack')
            ->with('default', 789, $this->greaterThan(0));

        $worker = $this->createWorkerWithDriver($driver, [
            'retry_base_delay' => 2,
            'retry_max_delay' => 300,
        ]);

        $worker->processOne();
    }

    public function testWorkerRetryDelayIsExponential(): void
    {
        $jobData = JobDataFactory::running(['id' => 300, 'type' => 'test.job', 'attempts' => 0, 'maxAttempts' => 5]);
        $driver = $this->prepareProcessingScenario(
            $jobData,
            $this->handlerThrowing('Temporary failure')
        );

        $this->storage->expects($this->once())
            ->method('scheduleRetry')
            ->with(
                $this->callback(fn($claim) => $claim instanceof \Oeltima\SimpleQueue\Contract\ClaimedJob && $claim->job->id === 300),
                1,
                2,
                $this->isString()
            )
            ->willReturn(true);

        $driver->expects($this->once())->method('nack')->with('default', 300, 2);

        $worker = $this->createWorkerWithDriver($driver, [
            'retry_base_delay' => 2,
            'retry_max_delay' => 300,
        ]);

        $worker->processOne();
    }

    public function testWorkerRetryDelayCappedAtMaxDelay(): void
    {
        $jobData = JobDataFactory::running(['id' => 400, 'type' => 'test.job', 'attempts' => 8, 'maxAttempts' => 15]);
        $driver = $this->prepareProcessingScenario(
            $jobData,
            $this->handlerThrowing('Temporary failure')
        );

        $this->storage->expects($this->once())
            ->method('scheduleRetry')
            ->with(
                $this->callback(fn($claim) => $claim instanceof \Oeltima\SimpleQueue\Contract\ClaimedJob && $claim->job->id === 400),
                9,
                300,
                $this->isString()
            )
            ->willReturn(true);

        $driver->expects($this->once())->method('nack')->with('default', 400, 300);

        $worker = $this->createWorkerWithDriver($driver, [
            'retry_base_delay' => 2,
            'retry_max_delay' => 300,
        ]);

        $worker->processOne();
    }
}
