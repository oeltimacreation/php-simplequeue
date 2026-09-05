<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Integration;

use Oeltima\SimpleQueue\Contract\JobHandlerInterface;
use Oeltima\SimpleQueue\Contract\JobStatus;
use Oeltima\SimpleQueue\Driver\InMemoryQueueDriver;
use Oeltima\SimpleQueue\JobDispatcher;
use Oeltima\SimpleQueue\JobRegistry;
use Oeltima\SimpleQueue\QueueManager;
use Oeltima\SimpleQueue\Storage\InMemoryJobStorage;
use Oeltima\SimpleQueue\Tests\Support\FrozenClock;
use Oeltima\SimpleQueue\Worker;
use PHPUnit\Framework\TestCase;

class CrashRecoveryTest extends TestCase
{
    private InMemoryJobStorage $storage;
    private InMemoryQueueDriver $driver;
    private QueueManager $queueManager;
    private JobDispatcher $dispatcher;
    private JobRegistry $registry;
    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock();
        $this->storage = new InMemoryJobStorage($this->clock);
        $this->driver = new InMemoryQueueDriver($this->clock);
        $this->queueManager = new QueueManager($this->driver);
        $this->registry = new JobRegistry();
        $this->dispatcher = new JobDispatcher($this->storage, $this->queueManager);
    }

    private function simulateCrash(int $jobId): void
    {
        $this->driver->dequeue('default', 0);
        $this->storage->claimById($jobId, 'crashed-worker:1234');
        $this->clock->advance(700);
    }

    public function testWorkerRecoveryAfterSimulatedCrash(): void
    {
        $handler = new class implements JobHandlerInterface {
            public function handle(int $jobId, array $payload, ?callable $progressCallback = null): mixed
            {
                return ['recovered' => true];
            }
        };

        $this->registry->register('test.crash', get_class($handler));

        $jobId = $this->dispatcher->dispatch('test.crash', ['data' => 'important']);

        $this->simulateCrash($jobId);

        $job = $this->storage->find($jobId);
        self::assertNotNull($job);
        self::assertSame(JobStatus::Running, $job->status);

        $worker = new Worker(
            $this->storage,
            $this->queueManager,
            $this->registry,
            null,
            'default',
            ['lock_file' => null, 'poll_timeout' => 0, 'stuck_job_ttl' => 600, 'stop_when_empty' => true]
        );

        self::assertSame(Worker::EXIT_SUCCESS, $worker->run());

        $job = $this->storage->find($jobId);
        self::assertNotNull($job);
        self::assertSame(JobStatus::Completed, $job->status);
        self::assertSame(['recovered' => true], $job->result);
    }

    public function testMultipleStaleJobsRecovery(): void
    {
        $handler = new class implements JobHandlerInterface {
            public function handle(int $jobId, array $payload, ?callable $progressCallback = null): mixed
            {
                return ['index' => $payload['index']];
            }
        };

        $this->registry->register('test.stale', get_class($handler));

        $jobIds = [];
        for ($i = 0; $i < 3; $i++) {
            $jobIds[] = $this->dispatcher->dispatch('test.stale', ['index' => $i]);
        }

        foreach ($jobIds as $jobId) {
            $this->simulateCrash($jobId);
        }

        foreach ($jobIds as $jobId) {
            $job = $this->storage->find($jobId);
            self::assertNotNull($job);
            self::assertSame(JobStatus::Running, $job->status);
        }

        $worker = new Worker(
            $this->storage,
            $this->queueManager,
            $this->registry,
            null,
            'default',
            ['lock_file' => null, 'poll_timeout' => 0, 'stuck_job_ttl' => 600, 'stop_when_empty' => true]
        );

        self::assertSame(Worker::EXIT_SUCCESS, $worker->run());

        foreach ($jobIds as $jobId) {
            $job = $this->storage->find($jobId);
            self::assertNotNull($job);
            self::assertSame(JobStatus::Completed, $job->status);
        }
    }
}
