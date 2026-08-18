<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Unit;

use Oeltima\SimpleQueue\Contract\QueueDriverInterface;
use Oeltima\SimpleQueue\Tests\Support\JobDataFactory;
use Oeltima\SimpleQueue\Tests\Support\WorkerTestCase;

final class WorkerProcessingTest extends WorkerTestCase
{
    public function testWorkerContinuesWhenStorageThrowsExceptionDuringClaim(): void
    {
        $driver = $this->createMock(QueueDriverInterface::class);
        $driver->expects($this->once())->method('dequeue')->willReturn(123);
        $driver->expects($this->never())->method('ack');

        $this->storage->expects($this->once())
            ->method('claimById')
            ->willThrowException(new \RuntimeException('Database connection lost'));

        $this->logger->expects($this->atLeastOnce())
            ->method('error')
            ->with(
                'Failed to claim job from storage',
                $this->callback(fn(array $context): bool => isset($context['job_id']) && $context['job_id'] === 123)
            );

        $this->assertFalse($this->createWorkerWithDriver($driver)->processOne());
    }

    public function testWorkerContinuesWhenJobAlreadyClaimedByAnotherWorker(): void
    {
        $driver = $this->createMock(QueueDriverInterface::class);
        $driver->expects($this->once())->method('dequeue')->willReturn(456);
        $driver->expects($this->once())->method('ack')->with('default', 456);

        $this->storage->expects($this->once())->method('claimById')->willReturn(null);

        $this->logger->expects($this->atLeastOnce())
            ->method('warning')
            ->with(
                'Failed to claim job, may have been claimed by another process',
                $this->callback(fn(array $context): bool => isset($context['job_id']) && $context['job_id'] === 456)
            );

        $this->assertFalse($this->createWorkerWithDriver($driver)->processOne());
    }

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

    public function testProcessOneNonBlockingReturnsFalseWhenQueueEmpty(): void
    {
        $driver = $this->createMock(QueueDriverInterface::class);
        $driver->expects($this->once())
            ->method('dequeue')
            ->with('default', 0)
            ->willReturn(null);

        $worker = $this->createWorkerWithDriver($driver);
        $result = $worker->processOne();

        $this->assertFalse($result);
    }

    public function testProcessOneSuccessfulJobCompletion(): void
    {
        $jobData = JobDataFactory::running(['id' => 100, 'type' => 'test.job', 'attempts' => 0, 'maxAttempts' => 3]);
        $driver = $this->prepareProcessingScenario(
            $jobData,
            $this->handlerReturning(['processed' => true])
        );

        $this->storage->expects($this->once())
            ->method('markCompleted')
            ->with($this->callback(fn($claim) => $claim instanceof \Oeltima\SimpleQueue\Contract\ClaimedJob && $claim->job->id === 100), ['processed' => true])
            ->willReturn(true);

        $driver->expects($this->once())->method('ack')->with('default', 100);

        $this->assertTrue($this->createWorkerWithDriver($driver)->processOne());
    }

    public function testProcessOneJobFailedAfterMaxAttempts(): void
    {
        $jobData = JobDataFactory::running(['id' => 200, 'type' => 'test.job', 'attempts' => 2, 'maxAttempts' => 3]);
        $driver = $this->prepareProcessingScenario(
            $jobData,
            $this->handlerThrowing('Job failed permanently')
        );

        $this->storage->expects($this->once())
            ->method('markFailed')
            ->with(
                $this->callback(fn($claim) => $claim instanceof \Oeltima\SimpleQueue\Contract\ClaimedJob && $claim->job->id === 200),
                $this->isString(),
                $this->anything()
            )
            ->willReturn(true);

        $driver->expects($this->once())->method('ack')->with('default', 200);
        $this->storage->expects($this->never())->method('scheduleRetry');

        $this->assertTrue($this->createWorkerWithDriver($driver)->processOne());
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

    public function testWorkerHandlesAckExceptionAfterCompletedJob(): void
    {
        $jobData = JobDataFactory::running(['id' => 500, 'type' => 'test.job', 'attempts' => 0, 'maxAttempts' => 3]);
        $driver = $this->prepareProcessingScenario(
            $jobData,
            $this->handlerReturning(['done' => true])
        );

        $this->storage->expects($this->once())
            ->method('markCompleted')
            ->with(
                $this->callback(fn($claim) => $claim instanceof \Oeltima\SimpleQueue\Contract\ClaimedJob && $claim->job->id === 500),
                ['done' => true]
            )
            ->willReturn(true);

        $driver->expects($this->once())
            ->method('ack')
            ->willThrowException(new \RuntimeException('Redis error'));

        $this->logger->expects($this->atLeastOnce())
            ->method('error')
            ->with(
                'Failed to ack completed job',
                $this->callback(fn(array $context): bool => isset($context['job_id']) && $context['job_id'] === 500)
            );

        $this->assertTrue($this->createWorkerWithDriver($driver)->processOne());
    }

    public function testHandleJobFailureCatchesStorageErrors(): void
    {
        $jobData = JobDataFactory::running(['id' => 999, 'type' => 'test.job', 'attempts' => 0, 'maxAttempts' => 3]);
        $driver = $this->prepareProcessingScenario($jobData, $this->handlerThrowing('Job failed'));

        $this->storage->expects($this->once())
            ->method('scheduleRetry')
            ->willThrowException(new \RuntimeException('Storage error during retry'));

        $this->logger->expects($this->atLeastOnce())
            ->method('error');

        $this->assertTrue($this->createWorkerWithDriver($driver)->processOne());
    }

    public function testProgressCallbackTriggersUpdateProgressWithoutRedundantStorageHeartbeat(): void
    {
        $jobData = JobDataFactory::running(['id' => 123, 'type' => 'test.job']);
        $driver = $this->prepareProcessingScenario(
            $jobData,
            $this->handlerWithProgress(45, 'Progress message', true)
        );

        $this->storage->expects($this->once())
            ->method('updateProgress')
            ->with(
                $this->callback(
                    fn($claim): bool =>
                        $claim instanceof \Oeltima\SimpleQueue\Contract\ClaimedJob && $claim->job->id === 123
                ),
                45,
                'Progress message'
            )
            ->willReturn(true);

        $this->storage->expects($this->never())->method('heartbeat');

        $this->storage->expects($this->once())
            ->method('markCompleted')
            ->willReturn(true);

        $worker = $this->createWorkerWithDriver($driver);
        $worker->processOne();
    }

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
