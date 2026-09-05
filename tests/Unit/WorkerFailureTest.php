<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Unit;

use Oeltima\SimpleQueue\Tests\Support\JobDataFactory;
use Oeltima\SimpleQueue\Tests\Support\WorkerTestCase;

final class WorkerFailureTest extends WorkerTestCase
{
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
                self::callback(fn($claim) => $claim instanceof \Oeltima\SimpleQueue\Contract\ClaimedJob && $claim->job->id === 200),
                self::isString(),
                self::anything()
            )
            ->willReturn(true);

        $driver->expects($this->once())->method('ack')->with('default', 200);
        $this->storage->expects($this->never())->method('scheduleRetry');

        $events = [];
        $worker = $this->createWorkerWithDriver($driver, [
            'event_listener' => static function (string $name) use (&$events): void {
                $events[] = $name;
            },
        ]);

        self::assertTrue($worker->processOne());
        self::assertSame(['claimed', 'failed'], $events);
    }

    public function testHandleJobFailureCatchesStorageErrors(): void
    {
        $jobData = JobDataFactory::running(['id' => 999, 'type' => 'test.job', 'attempts' => 0, 'maxAttempts' => 3]);
        $driver = $this->prepareProcessingScenario($jobData, $this->handlerThrowing('Job failed'));

        $this->storage->expects($this->once())
            ->method('scheduleRetry')
            ->willThrowException(new \RuntimeException('Storage error during retry'));

        // Storage errors escape as infrastructure; they never masquerade as handler retry.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Storage error during retry');
        $this->createWorkerWithDriver($driver)->processOne();
    }
}
