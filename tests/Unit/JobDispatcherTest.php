<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Unit;

use Oeltima\SimpleQueue\Driver\InMemoryQueueDriver;
use Oeltima\SimpleQueue\JobDispatcher;
use Oeltima\SimpleQueue\QueueManager;
use Oeltima\SimpleQueue\Storage\InMemoryJobStorage;
use PHPUnit\Framework\TestCase;

class JobDispatcherTest extends TestCase
{
    private InMemoryJobStorage $storage;
    private InMemoryQueueDriver $driver;
    private QueueManager $queueManager;
    private JobDispatcher $dispatcher;

    protected function setUp(): void
    {
        $this->storage = new InMemoryJobStorage();
        $this->driver = new InMemoryQueueDriver();
        $this->queueManager = new QueueManager($this->driver);
        $this->dispatcher = new JobDispatcher($this->storage, $this->queueManager);
    }

    public function testDispatchCreatesJobInStorage(): void
    {
        $jobId = $this->dispatcher->dispatch('email.send', ['to' => 'test@example.com']);

        $this->assertGreaterThan(0, $jobId);

        $job = $this->storage->find($jobId);
        $this->assertNotNull($job);
        $this->assertEquals('email.send', $job->type);
        $this->assertEquals('pending', $job->status);
        $this->assertEquals(['to' => 'test@example.com'], $job->payload);
    }

    public function testDispatchEnqueuesJobInDriver(): void
    {
        $jobId = $this->dispatcher->dispatch('email.send', ['to' => 'test@example.com']);

        $pending = $this->driver->getPending('default');
        $this->assertContains($jobId, $pending);
    }

    public function testDispatchWithCustomQueue(): void
    {
        $jobId = $this->dispatcher->dispatch(
            type: 'email.send',
            payload: ['to' => 'test@example.com'],
            queue: 'emails'
        );

        $job = $this->storage->find($jobId);
        $this->assertEquals('emails', $job->queue);

        $pending = $this->driver->getPending('emails');
        $this->assertContains($jobId, $pending);
    }

    public function testDispatchWithMaxAttempts(): void
    {
        $jobId = $this->dispatcher->dispatch(
            type: 'email.send',
            payload: [],
            maxAttempts: 5
        );

        $job = $this->storage->find($jobId);
        $this->assertEquals(5, $job->maxAttempts);
    }

    public function testDispatchWithRequestId(): void
    {
        $jobId = $this->dispatcher->dispatch(
            type: 'email.send',
            payload: [],
            requestId: 'req-12345'
        );

        $job = $this->storage->find($jobId);
        $this->assertEquals('req-12345', $job->requestId);
    }

    public function testDispatchBatchCreatesMultipleJobs(): void
    {
        $payloads = [
            ['to' => 'user1@example.com'],
            ['to' => 'user2@example.com'],
            ['to' => 'user3@example.com'],
        ];

        $jobIds = $this->dispatcher->dispatchBatch('email.send', $payloads);

        $this->assertCount(3, $jobIds);

        foreach ($jobIds as $index => $jobId) {
            $job = $this->storage->find($jobId);
            $this->assertNotNull($job);
            $this->assertEquals('email.send', $job->type);
            $this->assertEquals($payloads[$index], $job->payload);
        }
    }

    public function testGetStatusReturnsJobData(): void
    {
        $jobId = $this->dispatcher->dispatch('test.job', ['key' => 'value']);

        $job = $this->dispatcher->getStatus($jobId);

        $this->assertNotNull($job);
        $this->assertEquals($jobId, $job->id);
        $this->assertEquals('test.job', $job->type);
    }

    public function testGetStatusReturnsNullForNonExistentJob(): void
    {
        $job = $this->dispatcher->getStatus(99999);

        $this->assertNull($job);
    }
}
