<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Support;

use Oeltima\SimpleQueue\Contract\JobData;
use Oeltima\SimpleQueue\Contract\JobHandlerInterface;
use Oeltima\SimpleQueue\Contract\JobStorageInterface;
use Oeltima\SimpleQueue\Contract\QueueDriverInterface;
use Oeltima\SimpleQueue\JobRegistry;
use Oeltima\SimpleQueue\QueueManager;
use Oeltima\SimpleQueue\Worker;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

abstract class WorkerTestCase extends TestCase
{
    /** @var JobStorageInterface&\PHPUnit\Framework\MockObject\MockObject */
    protected JobStorageInterface $storage;
    protected JobRegistry $registry;
    /** @var LoggerInterface&\PHPUnit\Framework\MockObject\MockObject */
    protected LoggerInterface $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = $this->createMock(JobStorageInterface::class);
        $this->registry = new JobRegistry();
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    /**
     * Create a worker with deterministic defaults for unit scenarios.
     *
     * @param QueueDriverInterface $driver Queue driver double
     * @param array<string, mixed> $options Worker option overrides
     * @return Worker Configured worker
     */
    protected function createWorkerWithDriver(QueueDriverInterface $driver, array $options = []): Worker
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

    /**
     * Arrange the shared claim, handler, and dequeue setup for a processing scenario.
     *
     * @param JobData $jobData Running job state under test
     * @param \Closure $handler Handler callback for the scenario
     * @return QueueDriverInterface&MockObject Queue driver double with the job dequeued once
     */
    protected function prepareProcessingScenario(JobData $jobData, \Closure $handler): QueueDriverInterface&MockObject
    {
        $jobHandler = new class implements JobHandlerInterface {
            private static ?\Closure $handler = null;

            public static function setHandler(\Closure $handler): void
            {
                self::$handler = $handler;
            }

            public function handle(
                int $jobId,
                array $payload,
                ?callable $progressCallback = null
            ): mixed {
                if (self::$handler === null) {
                    throw new \LogicException('Worker test handler was not configured');
                }

                return (self::$handler)($jobId, $payload, $progressCallback);
            }
        };

        $jobHandler::setHandler($handler);
        $this->registry->register('test.job', get_class($jobHandler));

        $driver = $this->createMock(QueueDriverInterface::class);
        $driver->expects($this->once())->method('dequeue')->willReturn($jobData->id);
        $this->mockClaimById($jobData);

        return $driver;
    }

    /**
     * Create a handler callback that returns a fixed result.
     *
     * @param mixed $result Handler result
     * @return \Closure Handler callback
     */
    protected function handlerReturning(mixed $result): \Closure
    {
        return static fn(mixed ...$arguments): mixed => $result;
    }

    /**
     * Create a handler callback that throws a runtime failure.
     *
     * @param string $message Failure message
     * @return \Closure Handler callback
     */
    protected function handlerThrowing(string $message): \Closure
    {
        return static function (mixed ...$arguments) use ($message): mixed {
            throw new \RuntimeException($message);
        };
    }

    /**
     * Create a handler callback that reports progress before returning.
     *
     * @param int $progress Progress percentage
     * @param string $message Progress message
     * @param mixed $result Handler result
     * @return \Closure Handler callback
     */
    protected function handlerWithProgress(int $progress, string $message, mixed $result): \Closure
    {
        return static function (mixed ...$arguments) use ($progress, $message, $result): mixed {
            $progressCallback = $arguments[2] ?? null;
            if (is_callable($progressCallback)) {
                $progressCallback($progress, $message);
            }

            return $result;
        };
    }

    /**
     * Expect one storage claim and return a concrete lease for the scenario.
     *
     * @param JobData $jobData Job state returned by the claim
     * @param string $leaseToken Lease token
     */
    protected function mockClaimById(
        JobData $jobData,
        string $leaseToken = 'lease-token'
    ): void {
        $this->storage->expects($this->once())
            ->method('claimById')
            ->willReturnCallback(
                static fn(int $jobId, string $claimedWorkerId): \Oeltima\SimpleQueue\Contract\ClaimedJob =>
                    ClaimedJobFactory::create($jobData, $claimedWorkerId, $leaseToken)
            );
    }
}
