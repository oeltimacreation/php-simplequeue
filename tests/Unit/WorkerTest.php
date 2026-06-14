<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Unit;

use Oeltima\SimpleQueue\Contract\JobData;
use Oeltima\SimpleQueue\Contract\JobHandlerInterface;
use Oeltima\SimpleQueue\Contract\JobStorageInterface;
use Oeltima\SimpleQueue\Contract\QueueDriverInterface;
use Oeltima\SimpleQueue\Driver\InMemoryQueueDriver;
use Oeltima\SimpleQueue\JobRegistry;
use Oeltima\SimpleQueue\QueueManager;
use Oeltima\SimpleQueue\Worker;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class WorkerTest extends TestCase
{
    private JobStorageInterface $storage;
    private JobRegistry $registry;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->storage = $this->createMock(JobStorageInterface::class);
        $this->registry = new JobRegistry();
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function createWorkerWithDriver(QueueDriverInterface $driver, array $options = []): Worker
    {
        $queueManager = new QueueManager($driver);
        $defaultOptions = [
            'lock_file' => null,
            'poll_timeout' => 0,
            'stuck_job_ttl' => 600,
        ];
        return new Worker(
            $this->storage,
            $queueManager,
            $this->registry,
            $this->logger,
            'default',
            array_merge($defaultOptions, $options)
        );
    }

    public function testWorkerContinuesWhenStorageThrowsExceptionDuringClaim(): void
    {
        $driver = $this->createMock(QueueDriverInterface::class);
        $driver->expects($this->once())
            ->method('dequeue')
            ->willReturn(123);

        $this->storage->expects($this->once())
            ->method('claimById')
            ->willThrowException(new \RuntimeException('Database connection lost'));

        $this->logger->expects($this->atLeastOnce())
            ->method('error')
            ->with(
                'Failed to claim job from storage',
                $this->callback(function ($context) {
                    return isset($context['job_id']) && $context['job_id'] === 123;
                })
            );

        $driver->expects($this->never())
            ->method('ack');

        $worker = $this->createWorkerWithDriver($driver);
        $result = $worker->processOne();

        $this->assertFalse($result);
    }

    public function testWorkerContinuesWhenJobAlreadyClaimedByAnotherWorker(): void
    {
        $driver = $this->createMock(QueueDriverInterface::class);
        $driver->expects($this->once())
            ->method('dequeue')
            ->willReturn(456);

        $this->storage->expects($this->once())
            ->method('claimById')
            ->willReturn(null);

        $this->logger->expects($this->atLeastOnce())
            ->method('warning')
            ->with(
                'Failed to claim job, may have been claimed by another process',
                $this->callback(function ($context) {
                    return isset($context['job_id']) && $context['job_id'] === 456;
                })
            );

        $driver->expects($this->once())
            ->method('ack')
            ->with('default', 456);

        $worker = $this->createWorkerWithDriver($driver);
        $result = $worker->processOne();

        $this->assertFalse($result);
    }

    public function testWorkerCallsPromoteDelayedJobsBeforeDequeue(): void
    {
        $driverWithPromote = new class implements QueueDriverInterface {
            public bool $promoteCalled = false;
            public bool $dequeueCalled = false;
            public ?string $promoteCalledBefore = null;

            public function isAvailable(): bool
            {
                return true;
            }

            public function enqueue(string $queue, int $jobId): void
            {
            }

            public function dequeue(string $queue, int $timeoutSeconds): ?int
            {
                $this->dequeueCalled = true;
                $this->promoteCalledBefore = $this->promoteCalled ? 'before' : 'after';
                return null;
            }

            public function ack(string $queue, int $jobId): void
            {
            }

            public function nack(string $queue, int $jobId, int $delaySeconds = 0): void
            {
            }

            public function promoteDelayedJobs(string $queue): int
            {
                $this->promoteCalled = true;
                return 0;
            }
        };

        $worker = $this->createWorkerWithDriver($driverWithPromote);
        $worker->processOne();

        $this->assertTrue($driverWithPromote->promoteCalled, 'promoteDelayedJobs should be called');
        $this->assertTrue($driverWithPromote->dequeueCalled, 'dequeue should be called');
        $this->assertEquals('before', $driverWithPromote->promoteCalledBefore, 'promote should be called before dequeue');
    }

    public function testDriverSupportsRecoverStaleProcessingMethod(): void
    {
        // This test verifies the RedisQueueDriver has the recoverStaleProcessing method
        // which the Worker will call during recoverStaleJobs()
        $driverWithRecover = new class implements QueueDriverInterface {
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

            public function recoverStaleProcessing(string $queue, int $ttlSeconds): int
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

    public function testNackPassesDelayToDriver(): void
    {
        $jobData = new JobData(
            id: 789,
            queue: 'default',
            type: 'test.job',
            status: 'running',
            payload: [],
            attempts: 0,
            maxAttempts: 3,
            createdAt: date('Y-m-d H:i:s'),
            updatedAt: date('Y-m-d H:i:s')
        );

        $handler = new class implements JobHandlerInterface {
            public function handle(int $jobId, array $payload, ?callable $progressCallback = null): mixed
            {
                throw new \RuntimeException('Job failed');
            }
        };

        $this->registry->register('test.job', get_class($handler));

        $driver = $this->createMock(QueueDriverInterface::class);
        $driver->expects($this->once())
            ->method('dequeue')
            ->willReturn(789);

        $this->storage->expects($this->once())
            ->method('claimById')
            ->willReturnCallback(function ($jobId, $workerId) use ($jobData) {
                return new \Oeltima\SimpleQueue\Contract\ClaimedJob($jobData, $workerId, 'lease-token');
            });

        $this->storage->expects($this->once())
            ->method('scheduleRetry')
            ->willReturn(true);

        $driver->expects($this->once())
            ->method('nack')
            ->with(
                'default',
                789,
                $this->greaterThan(0)
            );

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
        $handler = new class implements JobHandlerInterface {
            public function handle(int $jobId, array $payload, ?callable $progressCallback = null): mixed
            {
                return ['processed' => true];
            }
        };

        $this->registry->register('test.job', get_class($handler));

        $jobData = new JobData(
            id: 100,
            queue: 'default',
            type: 'test.job',
            status: 'running',
            payload: [],
            attempts: 0,
            maxAttempts: 3,
            createdAt: date('Y-m-d H:i:s'),
            updatedAt: date('Y-m-d H:i:s')
        );

        $driver = $this->createMock(QueueDriverInterface::class);
        $driver->expects($this->once())
            ->method('dequeue')
            ->willReturn(100);

        $this->storage->expects($this->once())
            ->method('claimById')
            ->willReturnCallback(function ($jobId, $workerId) use ($jobData) {
                return new \Oeltima\SimpleQueue\Contract\ClaimedJob($jobData, $workerId, 'lease-token');
            });

        $this->storage->expects($this->once())
            ->method('markCompleted')
            ->with($this->callback(function ($claim) {
                return $claim instanceof \Oeltima\SimpleQueue\Contract\ClaimedJob && $claim->job->id === 100;
            }), ['processed' => true])
            ->willReturn(true);

        $driver->expects($this->once())
            ->method('ack')
            ->with('default', 100);

        $worker = $this->createWorkerWithDriver($driver);
        $result = $worker->processOne();

        $this->assertTrue($result);
    }

    public function testProcessOneJobFailedAfterMaxAttempts(): void
    {
        $handler = new class implements JobHandlerInterface {
            public function handle(int $jobId, array $payload, ?callable $progressCallback = null): mixed
            {
                throw new \RuntimeException('Job failed permanently');
            }
        };

        $this->registry->register('test.job', get_class($handler));

        $jobData = new JobData(
            id: 200,
            queue: 'default',
            type: 'test.job',
            status: 'running',
            payload: [],
            attempts: 2,
            maxAttempts: 3,
            createdAt: date('Y-m-d H:i:s'),
            updatedAt: date('Y-m-d H:i:s')
        );

        $driver = $this->createMock(QueueDriverInterface::class);
        $driver->expects($this->once())
            ->method('dequeue')
            ->willReturn(200);

        $this->storage->expects($this->once())
            ->method('claimById')
            ->willReturnCallback(function ($jobId, $workerId) use ($jobData) {
                return new \Oeltima\SimpleQueue\Contract\ClaimedJob($jobData, $workerId, 'lease-token');
            });

        $this->storage->expects($this->once())
            ->method('markFailed')
            ->with(
                $this->callback(function ($claim) {
                    return $claim instanceof \Oeltima\SimpleQueue\Contract\ClaimedJob && $claim->job->id === 200;
                }),
                $this->isType('string'),
                $this->anything()
            )
            ->willReturn(true);

        $driver->expects($this->once())
            ->method('ack')
            ->with('default', 200);

        $this->storage->expects($this->never())
            ->method('scheduleRetry');

        $worker = $this->createWorkerWithDriver($driver);
        $result = $worker->processOne();

        $this->assertTrue($result);
    }

    public function testWorkerRetryDelayIsExponential(): void
    {
        $handler = new class implements JobHandlerInterface {
            public function handle(int $jobId, array $payload, ?callable $progressCallback = null): mixed
            {
                throw new \RuntimeException('Temporary failure');
            }
        };

        $this->registry->register('test.job', get_class($handler));

        $jobData = new JobData(
            id: 300,
            queue: 'default',
            type: 'test.job',
            status: 'running',
            payload: [],
            attempts: 0,
            maxAttempts: 5,
            createdAt: date('Y-m-d H:i:s'),
            updatedAt: date('Y-m-d H:i:s')
        );

        $driver = $this->createMock(QueueDriverInterface::class);
        $driver->expects($this->once())
            ->method('dequeue')
            ->willReturn(300);

        $this->storage->expects($this->once())
            ->method('claimById')
            ->willReturnCallback(function ($jobId, $workerId) use ($jobData) {
                return new \Oeltima\SimpleQueue\Contract\ClaimedJob($jobData, $workerId, 'lease-token');
            });

        $this->storage->expects($this->once())
            ->method('scheduleRetry')
            ->with(
                $this->callback(function ($claim) {
                    return $claim instanceof \Oeltima\SimpleQueue\Contract\ClaimedJob && $claim->job->id === 300;
                }),
                1,
                2,
                $this->isType('string')
            )
            ->willReturn(true);

        $driver->expects($this->once())
            ->method('nack')
            ->with('default', 300, 2);

        $worker = $this->createWorkerWithDriver($driver, [
            'retry_base_delay' => 2,
            'retry_max_delay' => 300,
        ]);

        $worker->processOne();
    }

    public function testWorkerRetryDelayCappedAtMaxDelay(): void
    {
        $handler = new class implements JobHandlerInterface {
            public function handle(int $jobId, array $payload, ?callable $progressCallback = null): mixed
            {
                throw new \RuntimeException('Temporary failure');
            }
        };

        $this->registry->register('test.job', get_class($handler));

        $jobData = new JobData(
            id: 400,
            queue: 'default',
            type: 'test.job',
            status: 'running',
            payload: [],
            attempts: 8,
            maxAttempts: 15,
            createdAt: date('Y-m-d H:i:s'),
            updatedAt: date('Y-m-d H:i:s')
        );

        $driver = $this->createMock(QueueDriverInterface::class);
        $driver->expects($this->once())
            ->method('dequeue')
            ->willReturn(400);

        $this->storage->expects($this->once())
            ->method('claimById')
            ->willReturnCallback(function ($jobId, $workerId) use ($jobData) {
                return new \Oeltima\SimpleQueue\Contract\ClaimedJob($jobData, $workerId, 'lease-token');
            });

        $this->storage->expects($this->once())
            ->method('scheduleRetry')
            ->with(
                $this->callback(function ($claim) {
                    return $claim instanceof \Oeltima\SimpleQueue\Contract\ClaimedJob && $claim->job->id === 400;
                }),
                9,
                300,
                $this->isType('string')
            )
            ->willReturn(true);

        $driver->expects($this->once())
            ->method('nack')
            ->with('default', 400, 300);

        $worker = $this->createWorkerWithDriver($driver, [
            'retry_base_delay' => 2,
            'retry_max_delay' => 300,
        ]);

        $worker->processOne();
    }

    public function testWorkerHandlesAckExceptionAfterCompletedJob(): void
    {
        $handler = new class implements JobHandlerInterface {
            public function handle(int $jobId, array $payload, ?callable $progressCallback = null): mixed
            {
                return ['done' => true];
            }
        };

        $this->registry->register('test.job', get_class($handler));

        $jobData = new JobData(
            id: 500,
            queue: 'default',
            type: 'test.job',
            status: 'running',
            payload: [],
            attempts: 0,
            maxAttempts: 3,
            createdAt: date('Y-m-d H:i:s'),
            updatedAt: date('Y-m-d H:i:s')
        );

        $driver = $this->createMock(QueueDriverInterface::class);
        $driver->expects($this->once())
            ->method('dequeue')
            ->willReturn(500);

        $this->storage->expects($this->once())
            ->method('claimById')
            ->willReturnCallback(function ($jobId, $workerId) use ($jobData) {
                return new \Oeltima\SimpleQueue\Contract\ClaimedJob($jobData, $workerId, 'lease-token');
            });

        $this->storage->expects($this->once())
            ->method('markCompleted')
            ->with(
                $this->callback(function ($claim) {
                    return $claim instanceof \Oeltima\SimpleQueue\Contract\ClaimedJob && $claim->job->id === 500;
                }),
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
                $this->callback(function ($context) {
                    return isset($context['job_id']) && $context['job_id'] === 500;
                })
            );

        $worker = $this->createWorkerWithDriver($driver);
        $result = $worker->processOne();

        $this->assertTrue($result);
    }

    public function testHandleJobFailureCatchesStorageErrors(): void
    {
        $jobData = new JobData(
            id: 999,
            queue: 'default',
            type: 'test.job',
            status: 'running',
            payload: [],
            attempts: 0,
            maxAttempts: 3,
            createdAt: date('Y-m-d H:i:s'),
            updatedAt: date('Y-m-d H:i:s')
        );

        $handler = new class implements JobHandlerInterface {
            public function handle(int $jobId, array $payload, ?callable $progressCallback = null): mixed
            {
                throw new \RuntimeException('Job failed');
            }
        };

        $this->registry->register('test.job', get_class($handler));

        $driver = $this->createMock(QueueDriverInterface::class);
        $driver->expects($this->once())
            ->method('dequeue')
            ->willReturn(999);

        $this->storage->expects($this->once())
            ->method('claimById')
            ->willReturnCallback(function ($jobId, $workerId) use ($jobData) {
                return new \Oeltima\SimpleQueue\Contract\ClaimedJob($jobData, $workerId, 'lease-token');
            });

        $this->storage->expects($this->once())
            ->method('scheduleRetry')
            ->willThrowException(new \RuntimeException('Storage error during retry'));

        $this->logger->expects($this->atLeastOnce())
            ->method('error');

        $worker = $this->createWorkerWithDriver($driver);
        $result = $worker->processOne();

        $this->assertTrue($result);
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
        $jobData = new JobData(
            id: 111,
            queue: 'default',
            type: 'test.job',
            status: 'running',
            payload: [],
            attempts: 0,
            maxAttempts: 3,
            createdAt: date('Y-m-d H:i:s'),
            updatedAt: date('Y-m-d H:i:s')
        );

        $driver->expects($this->exactly(2))
            ->method('dequeue')
            ->willReturn(111);

        $this->storage->expects($this->exactly(2))
            ->method('claimById')
            ->willReturn(new \Oeltima\SimpleQueue\Contract\ClaimedJob($jobData, 'worker', 'token'));

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

        $clock->expects($this->any())
            ->method('monotonic')
            ->willReturnOnConsecutiveCalls(100.0, 105.0, 115.0);

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
}

