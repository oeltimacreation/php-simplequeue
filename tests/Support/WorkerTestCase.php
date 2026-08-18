<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Support;

use Oeltima\SimpleQueue\Contract\JobData;
use Oeltima\SimpleQueue\Contract\JobStorageInterface;
use Oeltima\SimpleQueue\Contract\QueueDriverInterface;
use Oeltima\SimpleQueue\JobRegistry;
use Oeltima\SimpleQueue\QueueManager;
use Oeltima\SimpleQueue\Worker;
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
