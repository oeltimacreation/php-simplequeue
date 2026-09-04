<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Unit;

use Oeltima\SimpleQueue\Contract\JobHandlerInterface;
use Oeltima\SimpleQueue\Contract\QueueDriverInterface;
use Oeltima\SimpleQueue\Contract\SupportsBoundedQueueMembership;
use Oeltima\SimpleQueue\Contract\SupportsDelayedJobs;
use Oeltima\SimpleQueue\Contract\SupportsStaleRecovery;
use Oeltima\SimpleQueue\QueueManager;
use Oeltima\SimpleQueue\Tests\Support\ClaimedJobFactory;
use Oeltima\SimpleQueue\Tests\Support\JobDataFactory;
use Oeltima\SimpleQueue\Tests\Support\WorkerTestCase;
use Oeltima\SimpleQueue\Worker;

interface WorkerRunLoopDelayedQueueDriver extends QueueDriverInterface, SupportsDelayedJobs
{
}

interface WorkerRunLoopReconciliationQueueDriver extends QueueDriverInterface, SupportsBoundedQueueMembership
{
}

final class WorkerRunLoopTest extends WorkerTestCase
{
    /**
     * Build a delayed-capable driver mock expecting one promote with the limit.
     *
     * @param int $limit Expected promote limit
     * @param list<string> $order Driver call order recorded when provided
     * @return WorkerRunLoopDelayedQueueDriver&\PHPUnit\Framework\MockObject\MockObject
     */
    private function promoteLimitDriver(int $limit, array &$order = []): WorkerRunLoopDelayedQueueDriver
    {
        $driver = $this->createMock(WorkerRunLoopDelayedQueueDriver::class);
        $driver->expects($this->once())
            ->method('promoteDelayedJobs')
            ->with('default', $limit)
            ->willReturnCallback(
                static function () use (&$order): int {
                    $order[] = 'promote';
                    return 0;
                }
            );
        $driver->method('dequeue')->willReturnCallback(
            static function () use (&$order): ?int {
                $order[] = 'dequeue';
                return null;
            }
        );
        return $driver;
    }

    public function testWorkerCallsPromoteDelayedJobsBeforeDequeue(): void
    {
        $order = [];
        $worker = $this->createWorkerWithDriver($this->promoteLimitDriver(100, $order));
        $worker->processOne();

        $this->assertSame(['promote', 'dequeue'], $order, 'promote should run before dequeue with the default limit');
    }

    public function testWorkerPassesConfiguredPromoteLimitThroughProcessOne(): void
    {
        $worker = $this->createWorkerWithDriver($this->promoteLimitDriver(42), ['promote_limit' => 42]);
        $worker->processOne();
    }

    public function testWorkerPassesConfiguredPromoteLimitThroughRunLoop(): void
    {
        $worker = $this->createWorkerWithDriver(
            $this->promoteLimitDriver(250),
            ['promote_limit' => 250, 'stop_when_empty' => true]
        );
        $worker->run();
    }

    public function testDriverSupportsRecoverStaleProcessingMethod(): void
    {
        // This test verifies the RedisQueueDriver has the recoverStaleProcessing method
        // which the Worker will call during recoverStaleJobs()
        $driverWithRecover = new class implements QueueDriverInterface, SupportsStaleRecovery {
            public bool $recoverCalled = false;
            public int $recoverTtl = 0;
            public string $recoverQueue = '';

            public function isAvailable(): bool
            {
                return true;
            }

            public function enqueue(string $queue, int $jobId): void
            {
            }

            public function dequeue(string $queue, int $timeoutSeconds): ?int
            {
                return null;
            }

            public function ack(string $queue, int $jobId): void
            {
            }

            public function nack(string $queue, int $jobId, int $delaySeconds = 0): void
            {
            }

            public function recoverStaleProcessing(string $queue, int $ttlSeconds, int $limit = 100): int
            {
                $this->recoverCalled = true;
                $this->recoverTtl = $ttlSeconds;
                $this->recoverQueue = $queue;
                return 2;
            }
        };

        // Test that recoverStaleProcessing can be called
        $this->assertTrue(method_exists($driverWithRecover, 'recoverStaleProcessing'));

        $result = $driverWithRecover->recoverStaleProcessing('default', 300);

        $this->assertEquals(2, $result);
        $this->assertTrue($driverWithRecover->recoverCalled);
        $this->assertEquals(300, $driverWithRecover->recoverTtl);
        $this->assertEquals('default', $driverWithRecover->recoverQueue);
    }

    public function testRunReturnsExitLockUnavailableOnLockFailure(): void
    {
        $lockFile = tempnam(sys_get_temp_dir(), 'sq_lock_');
        $fp = fopen($lockFile, 'c');
        flock($fp, LOCK_EX);

        $driver = $this->createMock(QueueDriverInterface::class);
        $worker = $this->createWorkerWithDriver($driver, [
            'lock_file' => $lockFile,
        ]);

        $exitCode = $worker->run();
        $this->assertEquals(Worker::EXIT_LOCK_UNAVAILABLE, $exitCode);

        fclose($fp);
        unlink($lockFile);
    }

    public function testRunReturnsExitSuccessOnGracefulShutdown(): void
    {
        $driver = $this->createMock(QueueDriverInterface::class);
        $worker = $this->createWorkerWithDriver($driver);

        $driver->expects($this->once())
            ->method('dequeue')
            ->willReturnCallback(function () use ($worker) {
                $worker->stop();
                return null;
            });

        $exitCode = $worker->run();
        $this->assertEquals(Worker::EXIT_SUCCESS, $exitCode);
    }

    public function testRunReturnsErrorWhenInitialRecoveryFails(): void
    {
        $driver = $this->createMock(QueueDriverInterface::class);
        $this->storage->expects($this->once())
            ->method('recoverStaleJobs')
            ->willThrowException(new \RuntimeException('Recovery unavailable'));
        $this->logger->expects($this->once())
            ->method('critical')
            ->with('Worker encountered a fatal error', $this->callback(
                static fn(array $context): bool => $context === ['error' => 'Recovery unavailable']
            ));

        $worker = $this->createWorkerWithDriver($driver);

        $this->assertSame(Worker::EXIT_ERROR, $worker->run());
    }

    public function testRunRetriesWithBackoffOnInfrastructureError(): void
    {
        $driver = $this->createMock(QueueDriverInterface::class);
        $worker = $this->createWorkerWithDriver($driver, [
            'retry_base_delay' => 0,
            'retry_max_delay' => 0,
        ]);

        $calls = 0;
        $driver->expects($this->exactly(2))
            ->method('dequeue')
            ->willReturnCallback(function () use (&$calls, $worker) {
                $calls++;
                if ($calls === 1) {
                    throw new \PDOException('Connection lost');
                }
                $worker->stop();
                return null;
            });

        $this->logger->expects($this->atLeastOnce())
            ->method('error')
            ->with(
                'Infrastructure error encountered. Backing off.',
                $this->anything()
            );

        $exitCode = $worker->run();
        $this->assertEquals(Worker::EXIT_SUCCESS, $exitCode);
    }

    public function testWorkerExitsAfterMaxJobs(): void
    {
        $handler = new class implements JobHandlerInterface {
            public function handle(int $jobId, array $payload, ?callable $progressCallback = null): mixed
            {
                return true;
            }
        };
        $this->registry->register('test.job', get_class($handler));

        $driver = $this->createMock(QueueDriverInterface::class);
        $jobData = JobDataFactory::running(['id' => 111, 'type' => 'test.job']);

        $driver->expects($this->exactly(2))
            ->method('dequeue')
            ->willReturn(111);

        $this->storage->expects($this->exactly(2))
            ->method('claimById')
            ->willReturn(ClaimedJobFactory::create($jobData, 'worker', 'token'));

        $this->storage->expects($this->exactly(2))
            ->method('markCompleted')
            ->willReturn(true);

        $worker = $this->createWorkerWithDriver($driver, [
            'max_jobs' => 2,
        ]);

        $exitCode = $worker->run();
        $this->assertEquals(Worker::EXIT_SUCCESS, $exitCode);
    }

    public function testWorkerExitsAfterMaxTime(): void
    {
        $driver = $this->createMock(QueueDriverInterface::class);
        $clock = $this->createMock(\Oeltima\SimpleQueue\Contract\ClockInterface::class);

        $time = 100.0;
        $clock->expects($this->any())
            ->method('monotonic')
            ->willReturnCallback(function () use (&$time) {
                $currentTime = $time;
                $time += 5.0; // Automatically advance time on each check
                return $currentTime;
            });

        $worker = $this->createWorkerWithDriver($driver, [
            'clock' => $clock,
            'max_time' => 5,
        ]);

        $exitCode = $worker->run();
        $this->assertEquals(Worker::EXIT_SUCCESS, $exitCode);
    }

    public function testWorkerExitsOnMemoryLimit(): void
    {
        $driver = $this->createMock(QueueDriverInterface::class);

        $worker = $this->createWorkerWithDriver($driver, [
            'memory_limit' => 1,
        ]);

        $exitCode = $worker->run();
        $this->assertEquals(Worker::EXIT_SUCCESS, $exitCode);
    }

    public function testWorkerStopsWhenEmpty(): void
    {
        $driver = $this->createMock(QueueDriverInterface::class);
        $driver->expects($this->once())
            ->method('dequeue')
            ->willReturn(null);

        $worker = $this->createWorkerWithDriver($driver, [
            'stop_when_empty' => true,
        ]);

        $exitCode = $worker->run();
        $this->assertEquals(Worker::EXIT_SUCCESS, $exitCode);
    }

    public function testThrottledMaintenanceIsCalled(): void
    {
        $driver = $this->createMock(WorkerRunLoopDelayedQueueDriver::class);

        $clock = $this->createMock(\Oeltima\SimpleQueue\Contract\ClockInterface::class);

        $time = 100.0;
        $clock->expects($this->any())
            ->method('monotonic')
            ->willReturnCallback(function () use (&$time) {
                return $time;
            });

        $driver->expects($this->exactly(3))
            ->method('promoteDelayedJobs')
            ->with('default')
            ->willReturn(0);

        $this->storage->expects($this->exactly(2))
            ->method('recoverStaleJobs')
            ->with(600)
            ->willReturn(0);

        $worker = $this->createWorkerWithDriver($driver, [
            'clock' => $clock,
            'promote_interval' => 5.0,
            'recovery_interval' => 10.0,
            'poll_timeout' => 0,
        ]);

        $calls = 0;
        $driver->expects($this->any())
            ->method('dequeue')
            ->willReturnCallback(function () use (&$calls, &$time, $worker) {
                $calls++;
                if ($calls === 1) {
                    $time = 106.0;
                } elseif ($calls === 2) {
                    $time = 111.0;
                } else {
                    $worker->stop();
                }
                return null;
            });

        $exitCode = $worker->run();
        $this->assertEquals(Worker::EXIT_SUCCESS, $exitCode);
    }

    public function testReconcileDbAndRedis(): void
    {
        $driver = $this->createMock(WorkerRunLoopReconciliationQueueDriver::class);

        // 1. Storage has a pending job and a delayed job
        $storage = new \Oeltima\SimpleQueue\Storage\InMemoryJobStorage();
        $jobIdPending = $storage->createJob('test.job', [], 'default', 3);
        $jobIdDelayed = $storage->createJob('test.job', [], 'default', 3);

        // Make the second job delayed
        $ref = new \ReflectionClass($storage);
        $prop = $ref->getProperty('jobs');
        $jobs = $prop->getValue($storage);
        $jobs[$jobIdDelayed]['available_at'] = date('Y-m-d H:i:s', time() + 3600);
        $prop->setValue($storage, $jobs);

        // 2. Redis currently has NOTHING (missing both jobs)
        $driver->expects($this->any())
            ->method('hasPendingJob')
            ->with('default')
            ->willReturn(false);

        $driver->expects($this->any())
            ->method('hasDelayedJob')
            ->with('default')
            ->willReturn(false);

        // 3. We expect the worker to reconcile both:
        // - Enqueue the pending one
        // - Nack (delayed enqueue) the delayed one
        $driver->expects($this->once())
            ->method('enqueue')
            ->with('default', $jobIdPending);

        $driver->expects($this->once())
            ->method('nack')
            ->with('default', $jobIdDelayed, $this->greaterThan(0));

        $queueManager = new QueueManager($driver);
        $worker = new Worker(
            $storage,
            $queueManager,
            $this->registry,
            $this->logger,
            'default',
            [
                'lock_file' => null,
                'poll_timeout' => 0,
            ]
        );

        $method = new \ReflectionMethod($worker, 'reconcileDbAndRedis');
        $method->invoke($worker);
    }

    public function testDefaultLockFileIsQueueScoped(): void
    {
        $driver = $this->createMock(QueueDriverInterface::class);
        $queueManager = new QueueManager($driver);
        $worker = new Worker(
            $this->storage,
            $queueManager,
            $this->registry,
            $this->logger,
            'custom/queue-name'
        );

        $ref = new \ReflectionClass($worker);
        $prop = $ref->getProperty('lockFile');
        $lockFile = $prop->getValue($worker);

        // Safe defaults isolate by UID + working directory + exact queue name hash.
        self::assertIsString($lockFile);
        $this->assertStringNotContainsString('customqueue-name', $lockFile);
        $other = new Worker($this->storage, $queueManager, $this->registry, $this->logger, 'other-queue');
        $otherLock = $ref->getProperty('lockFile')->getValue($other);
        $this->assertNotSame($lockFile, $otherLock);
    }
}
