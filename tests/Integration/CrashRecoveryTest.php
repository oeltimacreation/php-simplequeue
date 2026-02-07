<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Integration;

use Oeltima\SimpleQueue\Contract\JobHandlerInterface;
use Oeltima\SimpleQueue\Driver\InMemoryQueueDriver;
use Oeltima\SimpleQueue\JobDispatcher;
use Oeltima\SimpleQueue\JobRegistry;
use Oeltima\SimpleQueue\QueueManager;
use Oeltima\SimpleQueue\Storage\InMemoryJobStorage;
use Oeltima\SimpleQueue\Worker;
use PHPUnit\Framework\TestCase;

class CrashRecoveryTest extends TestCase
{
    private InMemoryJobStorage $storage;
    private InMemoryQueueDriver $driver;
    private QueueManager $queueManager;
    private JobDispatcher $dispatcher;
    private JobRegistry $registry;

    protected function setUp(): void
    {
        $this->storage = new InMemoryJobStorage();
        $this->driver = new InMemoryQueueDriver();
        $this->queueManager = new QueueManager($this->driver);
        $this->registry = new JobRegistry();
        $this->dispatcher = new JobDispatcher($this->storage, $this->queueManager);
    }

    private function simulateCrash(int $jobId): void
    {
        $this->driver->dequeue('default', 0);
        $this->storage->claimJob($jobId, 'crashed-worker:1234');

        $storageReflection = new \ReflectionClass($this->storage);
        $jobsProp = $storageReflection->getProperty('jobs');
        $jobsProp->setAccessible(true);
        $jobs = $jobsProp->getValue($this->storage);
        $jobs[$jobId]['locked_at'] = date('Y-m-d H:i:s', time() - 700);
        $jobs[$jobId]['status'] = 'running';
        $jobsProp->setValue($this->storage, $jobs);

        $driverReflection = new \ReflectionClass($this->driver);
        $prop = $driverReflection->getProperty('processingStartedAt');
        $prop->setAccessible(true);
        $times = $prop->getValue($this->driver);
        $times['default'][$jobId] = time() - 700;
        $prop->setValue($this->driver, $times);
    }

    private function invokeRecoverStaleJobs(Worker $worker): void
    {
        $reflection = new \ReflectionClass($worker);
        $method = $reflection->getMethod('recoverStaleJobs');
        $method->setAccessible(true);
        $method->invoke($worker);
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
        $this->assertSame('running', $job->status);

        $worker = new Worker(
            $this->storage,
            $this->queueManager,
            $this->registry,
            null,
            'default',
            ['lock_file' => null, 'poll_timeout' => 0, 'stuck_job_ttl' => 600]
        );

        $this->invokeRecoverStaleJobs($worker);

        $job = $this->storage->find($jobId);
        $this->assertSame('pending', $job->status);

        $processed = $worker->processOne();
        $this->assertTrue($processed);

        $job = $this->storage->find($jobId);
        $this->assertSame('completed', $job->status);
        $this->assertSame(['recovered' => true], $job->result);
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
            $this->assertSame('running', $job->status);
        }

        $worker = new Worker(
            $this->storage,
            $this->queueManager,
            $this->registry,
            null,
            'default',
            ['lock_file' => null, 'poll_timeout' => 0, 'stuck_job_ttl' => 600]
        );

        $this->invokeRecoverStaleJobs($worker);

        foreach ($jobIds as $jobId) {
            $job = $this->storage->find($jobId);
            $this->assertSame('pending', $job->status);
        }

        for ($i = 0; $i < 3; $i++) {
            $this->assertTrue($worker->processOne());
        }

        foreach ($jobIds as $jobId) {
            $job = $this->storage->find($jobId);
            $this->assertSame('completed', $job->status);
        }
    }
}
