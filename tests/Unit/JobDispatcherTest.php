<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Unit;

use Oeltima\SimpleQueue\Contract\JobStatus;
use Oeltima\SimpleQueue\Driver\InMemoryQueueDriver;
use Oeltima\SimpleQueue\JobDispatcher;
use Oeltima\SimpleQueue\QueueManager;
use Oeltima\SimpleQueue\Storage\InMemoryJobStorage;
use Oeltima\SimpleQueue\Tests\Support\FrozenClock;
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
        $this->assertSame(JobStatus::Pending, $job->status);
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

    public function testDispatchIdempotentCreatesNewJob(): void
    {
        $result = $this->dispatcher->dispatchIdempotent(
            'email.send',
            ['to' => 'test@example.com'],
            'req-unique-1'
        );

        $this->assertTrue($result['created']);
        $this->assertGreaterThan(0, $result['job_id']);

        $job = $this->storage->find($result['job_id']);
        $this->assertNotNull($job);
        $this->assertEquals('email.send', $job->type);
        $this->assertEquals('req-unique-1', $job->requestId);
    }

    public function testDispatchIdempotentReturnsExistingJobWhenDuplicate(): void
    {
        $first = $this->dispatcher->dispatchIdempotent(
            'email.send',
            ['to' => 'test@example.com'],
            'req-dup-1'
        );

        $second = $this->dispatcher->dispatchIdempotent(
            'email.send',
            ['to' => 'other@example.com'],
            'req-dup-1'
        );

        $this->assertTrue($first['created']);
        $this->assertFalse($second['created']);
        $this->assertEquals($first['job_id'], $second['job_id']);
    }

    public function testDispatchIdempotentCreatesNewAfterCompletion(): void
    {
        $first = $this->dispatcher->dispatchIdempotent(
            'email.send',
            ['to' => 'test@example.com'],
            'req-complete-1'
        );

        // Complete the first job
        $claim = $this->storage->claimById($first['job_id'], 'worker-1');
        $this->assertNotNull($claim);
        $this->storage->markCompleted($claim);

        $second = $this->dispatcher->dispatchIdempotent(
            'email.send',
            ['to' => 'test@example.com'],
            'req-complete-1'
        );

        $this->assertTrue($second['created']);
        $this->assertNotEquals($first['job_id'], $second['job_id']);
    }

    public function testDispatchIdempotentWithCustomQueue(): void
    {
        $result = $this->dispatcher->dispatchIdempotent(
            'email.send',
            ['to' => 'test@example.com'],
            'req-queue-1',
            'emails'
        );

        $job = $this->storage->find($result['job_id']);
        $this->assertEquals('emails', $job->queue);
        $this->assertTrue($result['created']);
    }

    public function testDispatchIdempotentEnqueuesInDriver(): void
    {
        $result = $this->dispatcher->dispatchIdempotent(
            'email.send',
            ['to' => 'test@example.com'],
            'req-driver-1'
        );

        $this->assertTrue($result['created']);
        $pending = $this->driver->getPending('default');
        $this->assertContains($result['job_id'], $pending);
    }

    public function testDispatchIdempotentDoesNotEnqueueDuplicate(): void
    {
        $first = $this->dispatcher->dispatchIdempotent('email.send', [], 'req-no-dup');
        $pendingBefore = count($this->driver->getPending('default'));

        $this->dispatcher->dispatchIdempotent('email.send', [], 'req-no-dup');
        $pendingAfter = count($this->driver->getPending('default'));

        $this->assertEquals($pendingBefore, $pendingAfter);
    }

    public function testCancelJobDelegatesToStorage(): void
    {
        $jobId = $this->dispatcher->dispatch('email.send', []);

        $result = $this->dispatcher->cancelJob($jobId);

        $this->assertTrue($result);
        $status = $this->dispatcher->getStatus($jobId);
        $this->assertSame(JobStatus::Cancelled, $status->status);
        $this->assertNotContains($jobId, $this->driver->getPending('default'));
    }

    public function testRepeatedCancellationRetriesNotificationCleanup(): void
    {
        $jobId = $this->dispatcher->dispatch('email.send', []);
        $this->assertTrue($this->dispatcher->cancelJob($jobId));
        $this->driver->enqueue('default', $jobId);

        $this->assertFalse($this->dispatcher->cancelJob($jobId));
        $this->assertNotContains($jobId, $this->driver->getPending('default'));
    }

    public function testDispatchRejectsInvalidPublicArguments(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->dispatcher->dispatch('', [], 'default', 0);
    }
    public function testDispatchWithFutureAvailableAtUsesDelayedNotification(): void
    {
        $clock = new FrozenClock();
        [$storage, $driver, $dispatcher] = $this->scheduledServices($clock);

        $jobId = $dispatcher->dispatch(
            'email.send',
            ['to' => 'a@example.com'],
            'default',
            3,
            null,
            $clock->timestamp() + 60
        );

        $job = $storage->find($jobId);
        $this->assertNotNull($job);
        $this->assertSame('2023-11-14 22:14:20', $job->availableAt);
        $this->assertSame(0, $driver->getPendingCount('default'));
        $this->assertSame(1, $driver->getDelayedCount('default'));
        $this->assertNull($driver->dequeue('default', 0));
        $this->assertSame(0, $driver->promoteDelayedJobs('default'));

        $clock->advance(60);
        $this->assertSame(1, $driver->promoteDelayedJobs('default'));
        $this->assertSame($jobId, $driver->dequeue('default', 0));
    }

    public function testDispatchWithFutureAvailableAtKeepsRequestId(): void
    {
        $clock = new FrozenClock();
        [$storage, $driver, $dispatcher] = $this->scheduledServices($clock);

        $jobId = $dispatcher->dispatch(
            'email.send',
            [],
            'default',
            3,
            'req-scheduled',
            $clock->timestamp() + 60
        );

        $this->assertSame('req-scheduled', $storage->find($jobId)?->requestId);
        $this->assertSame(1, $driver->getDelayedCount('default'));
    }

    public function testDispatchAtSchedulesJobAtAbsoluteTimestamp(): void
    {
        $clock = new FrozenClock();
        [$storage, $driver, $dispatcher] = $this->scheduledServices($clock);

        $jobId = $dispatcher->dispatchAt($clock->timestamp() + 30, 'email.send', []);

        $this->assertSame('2023-11-14 22:13:50', $storage->find($jobId)?->availableAt);
        $this->assertSame(1, $driver->getDelayedCount('default'));
    }

    public function testDispatchAtAcceptsDateTimeInterface(): void
    {
        $clock = new FrozenClock();
        [$storage, $driver, $dispatcher] = $this->scheduledServices($clock);

        $jobId = $dispatcher->dispatchAt(new \DateTimeImmutable('@' . ($clock->timestamp() + 90)), 'email.send', []);

        $this->assertSame('2023-11-14 22:14:50', $storage->find($jobId)?->availableAt);
        $this->assertSame(1, $driver->getDelayedCount('default'));
    }

    public function testDispatchAtClampsPastTimestampToImmediatePath(): void
    {
        $clock = new FrozenClock();
        [$storage, $driver, $dispatcher] = $this->scheduledServices($clock);

        $jobId = $dispatcher->dispatchAt($clock->timestamp() - 10, 'email.send', []);

        $this->assertSame(1, $driver->getPendingCount('default'));
        $this->assertSame(0, $driver->getDelayedCount('default'));
        $this->assertSame($clock->now(), $storage->find($jobId)?->availableAt);
    }

    public function testDispatchAtClampsNowToImmediatePath(): void
    {
        $clock = new FrozenClock();
        [, $driver, $dispatcher] = $this->scheduledServices($clock);

        $dispatcher->dispatchAt($clock->timestamp(), 'email.send', []);

        $this->assertSame(1, $driver->getPendingCount('default'));
        $this->assertSame(0, $driver->getDelayedCount('default'));
    }

    public function testDispatchAtRejectsNonPositiveTimestamp(): void
    {
        $clock = new FrozenClock();
        [, , $dispatcher] = $this->scheduledServices($clock);

        $this->expectException(\InvalidArgumentException::class);
        $dispatcher->dispatchAt(0, 'email.send', []);
    }

    public function testDispatchAfterSchedulesDelayUsingInjectedClock(): void
    {
        $clock = new FrozenClock();
        [$storage, $driver, $dispatcher] = $this->scheduledServices($clock);

        $jobId = $dispatcher->dispatchAfter(60, 'email.send', []);

        $this->assertSame('2023-11-14 22:14:20', $storage->find($jobId)?->availableAt);
        $this->assertSame(1, $driver->getDelayedCount('default'));
    }


    public function testDispatchAfterZeroIsImmediate(): void
    {
        $clock = new FrozenClock();
        [, $driver, $dispatcher] = $this->scheduledServices($clock);

        $dispatcher->dispatchAfter(0, 'email.send', []);

        $this->assertSame(1, $driver->getPendingCount('default'));
        $this->assertSame(0, $driver->getDelayedCount('default'));
    }

    public function testDispatchAfterRejectsNegativeDelay(): void
    {
        $clock = new FrozenClock();
        [, , $dispatcher] = $this->scheduledServices($clock);

        $this->expectException(\InvalidArgumentException::class);
        $dispatcher->dispatchAfter(-1, 'email.send', []);
    }

    public function testDispatchRejectsNonPositiveAvailableAt(): void
    {
        $clock = new FrozenClock();
        [, , $dispatcher] = $this->scheduledServices($clock);

        $this->expectException(\InvalidArgumentException::class);
        $dispatcher->dispatch('email.send', [], 'default', 3, null, 0);
    }

    public function testDispatchBatchWithFutureAvailableAtSchedulesAllDelayed(): void
    {
        $clock = new FrozenClock();
        [$storage, $driver, $dispatcher] = $this->scheduledServices($clock);

        $jobIds = $dispatcher->dispatchBatch(
            'email.send',
            [['n' => 1], ['n' => 2]],
            'default',
            3,
            $clock->timestamp() + 60
        );

        $this->assertCount(2, $jobIds);
        $this->assertSame(2, $driver->getDelayedCount('default'));
        $this->assertSame(0, $driver->getPendingCount('default'));
        foreach ($jobIds as $jobId) {
            $this->assertSame('2023-11-14 22:14:20', $storage->find($jobId)?->availableAt);
        }
    }

    public function testDispatchBatchWithPastAvailableAtIsImmediate(): void
    {
        $clock = new FrozenClock();
        [, $driver, $dispatcher] = $this->scheduledServices($clock);

        $jobIds = $dispatcher->dispatchBatch(
            'email.send',
            [['n' => 1], ['n' => 2]],
            'default',
            3,
            $clock->timestamp() - 5
        );

        $this->assertCount(2, $jobIds);
        $this->assertSame(2, $driver->getPendingCount('default'));
        $this->assertSame(0, $driver->getDelayedCount('default'));
    }

    public function testCancelScheduledJobRemovesDelayedNotification(): void
    {
        $clock = new FrozenClock();
        [$storage, $driver, $dispatcher] = $this->scheduledServices($clock);

        $jobId = $dispatcher->dispatch('email.send', [], 'default', 3, null, $clock->timestamp() + 60);
        $this->assertSame(1, $driver->getDelayedCount('default'));

        $this->assertTrue($dispatcher->cancelJob($jobId));

        $this->assertSame(0, $driver->getDelayedCount('default'));
        $this->assertSame(JobStatus::Cancelled, $storage->find($jobId)?->status);
    }

    /**
     * Build scheduled-dispatch services that share one frozen clock.
     *
     * @return array{InMemoryJobStorage, InMemoryQueueDriver, JobDispatcher}
     */
    private function scheduledServices(FrozenClock $clock): array
    {
        $storage = new InMemoryJobStorage($clock);
        $driver = new InMemoryQueueDriver($clock);
        $dispatcher = new JobDispatcher($storage, new QueueManager($driver), $clock);

        return [$storage, $driver, $dispatcher];
    }
}
