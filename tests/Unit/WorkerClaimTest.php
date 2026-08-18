<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Unit;

use Oeltima\SimpleQueue\Contract\QueueDriverInterface;
use Oeltima\SimpleQueue\Tests\Support\WorkerTestCase;

final class WorkerClaimTest extends WorkerTestCase
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
}
