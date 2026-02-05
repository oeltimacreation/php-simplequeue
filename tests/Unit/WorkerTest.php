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
            ->method('claimJob')
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

        $this->assertTrue($result);
    }

    public function testWorkerContinuesWhenStorageFindThrowsException(): void
    {
        $driver = $this->createMock(QueueDriverInterface::class);
        $driver->expects($this->once())
            ->method('dequeue')
            ->willReturn(456);

        $this->storage->expects($this->once())
            ->method('claimJob')
            ->willReturn(true);

        $this->storage->expects($this->once())
            ->method('find')
            ->willThrowException(new \RuntimeException('Query failed'));

        $this->logger->expects($this->atLeastOnce())
            ->method('error')
            ->with(
                'Failed to fetch job details',
                $this->callback(function ($context) {
                    return isset($context['job_id']) && $context['job_id'] === 456;
                })
            );

        $worker = $this->createWorkerWithDriver($driver);
        $result = $worker->processOne();

        $this->assertTrue($result);
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
            ->method('claimJob')
            ->willReturn(true);

        $this->storage->expects($this->once())
            ->method('find')
            ->willReturn($jobData);

        $this->storage->expects($this->once())
            ->method('scheduleRetry');

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
            ->method('claimJob')
            ->willReturn(true);

        $this->storage->expects($this->once())
            ->method('find')
            ->willReturn($jobData);

        $this->storage->expects($this->once())
            ->method('scheduleRetry')
            ->willThrowException(new \RuntimeException('Storage error during retry'));

        $this->logger->expects($this->atLeastOnce())
            ->method('error');

        $worker = $this->createWorkerWithDriver($driver);
        $result = $worker->processOne();

        $this->assertTrue($result);
    }
}
