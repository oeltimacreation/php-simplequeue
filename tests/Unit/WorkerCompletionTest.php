<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Unit;

use Oeltima\SimpleQueue\Tests\Support\JobDataFactory;
use Oeltima\SimpleQueue\Tests\Support\WorkerTestCase;

final class WorkerCompletionTest extends WorkerTestCase
{
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
}
