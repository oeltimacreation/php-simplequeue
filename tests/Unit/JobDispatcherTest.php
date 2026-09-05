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

        self::assertGreaterThan(0, $jobId);

        $job = $this->storage->find($jobId);
        self::assertNotNull($job);
        self::assertEquals('email.send', $job->type);
        self::assertSame(JobStatus::Pending, $job->status);
        self::assertEquals(['to' => 'test@example.com'], $job->payload);
    }

    public function testDispatchEnqueuesJobInDriver(): void
    {
        $jobId = $this->dispatcher->dispatch('email.send', ['to' => 'test@example.com']);

        $pending = $this->driver->getPending('default');
        self::assertContains($jobId, $pending);
    }

    public function testDispatchWithCustomQueue(): void
    {
        $jobId = $this->dispatcher->dispatch(
            type: 'email.send',
            payload: ['to' => 'test@example.com'],
            queue: 'emails'
        );

        $job = $this->storage->find($jobId);
        self::assertNotNull($job);
        self::assertEquals('emails', $job->queue);

        $pending = $this->driver->getPending('emails');
        self::assertContains($jobId, $pending);
    }

    public function testDispatchWithMaxAttempts(): void
    {
        $jobId = $this->dispatcher->dispatch(
            type: 'email.send',
            payload: [],
            maxAttempts: 5
        );

        $job = $this->storage->find($jobId);
        self::assertNotNull($job);
        self::assertEquals(5, $job->maxAttempts);
    }

    public function testDispatchWithRequestId(): void
    {
        $jobId = $this->dispatcher->dispatch(
            type: 'email.send',
            payload: [],
            requestId: 'req-12345'
        );

        $job = $this->storage->find($jobId);
        self::assertNotNull($job);
        self::assertEquals('req-12345', $job->requestId);
    }

    public function testDispatchBatchCreatesMultipleJobs(): void
    {
        $payloads = [
            ['to' => 'user1@example.com'],
            ['to' => 'user2@example.com'],
            ['to' => 'user3@example.com'],
        ];

        $jobIds = $this->dispatcher->dispatchBatch('email.send', $payloads);

        self::assertCount(3, $jobIds);

        foreach ($jobIds as $index => $jobId) {
            $job = $this->storage->find($jobId);
            self::assertNotNull($job);
            self::assertEquals('email.send', $job->type);
            self::assertEquals($payloads[$index], $job->payload);
        }
    }

    public function testGetStatusReturnsJobData(): void
    {
        $jobId = $this->dispatcher->dispatch('test.job', ['key' => 'value']);

        $job = $this->dispatcher->getStatus($jobId);

        self::assertNotNull($job);
        self::assertEquals($jobId, $job->id);
        self::assertEquals('test.job', $job->type);
        self::assertSame($this->storage, $this->dispatcher->getStorage());
        self::assertSame($this->queueManager, $this->dispatcher->getQueueManager());
    }

    public function testGetStatusReturnsNullForNonExistentJob(): void
    {
        $job = $this->dispatcher->getStatus(99999);

        self::assertNull($job);
    }

    public function testDispatchIdempotentCreatesNewJob(): void
    {
        $result = $this->dispatcher->dispatchIdempotent(
            'email.send',
            ['to' => 'test@example.com'],
            'req-unique-1'
        );

        self::assertTrue($result['created']);
        self::assertGreaterThan(0, $result['job_id']);

        $job = $this->storage->find($result['job_id']);
        self::assertNotNull($job);
        self::assertEquals('email.send', $job->type);
        self::assertEquals('req-unique-1', $job->requestId);
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

        self::assertTrue($first['created']);
        self::assertFalse($second['created']);
        self::assertEquals($first['job_id'], $second['job_id']);
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
        self::assertNotNull($claim);
        $this->storage->markCompleted($claim);

        $second = $this->dispatcher->dispatchIdempotent(
            'email.send',
            ['to' => 'test@example.com'],
            'req-complete-1'
        );

        self::assertTrue($second['created']);
        self::assertNotEquals($first['job_id'], $second['job_id']);
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
        self::assertNotNull($job);
        self::assertEquals('emails', $job->queue);
        self::assertTrue($result['created']);
    }

    public function testDispatchIdempotentEnqueuesInDriver(): void
    {
        $result = $this->dispatcher->dispatchIdempotent(
            'email.send',
            ['to' => 'test@example.com'],
            'req-driver-1'
        );

        self::assertTrue($result['created']);
        $pending = $this->driver->getPending('default');
        self::assertContains($result['job_id'], $pending);
    }

    public function testDispatchIdempotentDoesNotEnqueueDuplicate(): void
    {
        $first = $this->dispatcher->dispatchIdempotent('email.send', [], 'req-no-dup');
        $pendingBefore = count($this->driver->getPending('default'));

        $this->dispatcher->dispatchIdempotent('email.send', [], 'req-no-dup');
        $pendingAfter = count($this->driver->getPending('default'));

        self::assertEquals($pendingBefore, $pendingAfter);
    }

    public function testCancelJobDelegatesToStorage(): void
    {
        $jobId = $this->dispatcher->dispatch('email.send', []);

        $result = $this->dispatcher->cancelJob($jobId);

        self::assertTrue($result);
        $status = $this->dispatcher->getStatus($jobId);
        self::assertNotNull($status);
        self::assertSame(JobStatus::Cancelled, $status->status);
        self::assertNotContains($jobId, $this->driver->getPending('default'));
    }

    public function testRepeatedCancellationRetriesNotificationCleanup(): void
    {
        $jobId = $this->dispatcher->dispatch('email.send', []);
        self::assertTrue($this->dispatcher->cancelJob($jobId));
        $this->driver->enqueue('default', $jobId);

        self::assertFalse($this->dispatcher->cancelJob($jobId));
        self::assertNotContains($jobId, $this->driver->getPending('default'));
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
        self::assertNotNull($job);
        self::assertSame('2023-11-14 22:14:20', $job->availableAt);
        self::assertSame(0, $driver->getPendingCount('default'));
        self::assertSame(1, $driver->getDelayedCount('default'));
        self::assertNull($driver->dequeue('default', 0));
        self::assertSame(0, $driver->promoteDelayedJobs('default'));

        $clock->advance(60);
        self::assertSame(1, $driver->promoteDelayedJobs('default'));
        self::assertSame($jobId, $driver->dequeue('default', 0));
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

        self::assertSame('req-scheduled', $storage->find($jobId)?->requestId);
        self::assertSame(1, $driver->getDelayedCount('default'));
    }

    public function testDispatchAtSchedulesJobAtAbsoluteTimestamp(): void
    {
        $clock = new FrozenClock();
        [$storage, $driver, $dispatcher] = $this->scheduledServices($clock);

        $jobId = $dispatcher->dispatchAt($clock->timestamp() + 30, 'email.send', []);

        self::assertSame('2023-11-14 22:13:50', $storage->find($jobId)?->availableAt);
        self::assertSame(1, $driver->getDelayedCount('default'));
    }

    public function testDispatchAtAcceptsDateTimeInterface(): void
    {
        $clock = new FrozenClock();
        [$storage, $driver, $dispatcher] = $this->scheduledServices($clock);

        $jobId = $dispatcher->dispatchAt(new \DateTimeImmutable('@' . ($clock->timestamp() + 90)), 'email.send', []);

        self::assertSame('2023-11-14 22:14:50', $storage->find($jobId)?->availableAt);
        self::assertSame(1, $driver->getDelayedCount('default'));
    }

    public function testDispatchAtClampsPastTimestampToImmediatePath(): void
    {
        $clock = new FrozenClock();
        [$storage, $driver, $dispatcher] = $this->scheduledServices($clock);

        $jobId = $dispatcher->dispatchAt($clock->timestamp() - 10, 'email.send', []);

        self::assertSame(1, $driver->getPendingCount('default'));
        self::assertSame(0, $driver->getDelayedCount('default'));
        self::assertSame($clock->now(), $storage->find($jobId)?->availableAt);
    }

    public function testDispatchAtClampsNowToImmediatePath(): void
    {
        $clock = new FrozenClock();
        [, $driver, $dispatcher] = $this->scheduledServices($clock);

        $dispatcher->dispatchAt($clock->timestamp(), 'email.send', []);

        self::assertSame(1, $driver->getPendingCount('default'));
        self::assertSame(0, $driver->getDelayedCount('default'));
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

        self::assertSame('2023-11-14 22:14:20', $storage->find($jobId)?->availableAt);
        self::assertSame(1, $driver->getDelayedCount('default'));
    }


    public function testDispatchAfterZeroIsImmediate(): void
    {
        $clock = new FrozenClock();
        [, $driver, $dispatcher] = $this->scheduledServices($clock);

        $dispatcher->dispatchAfter(0, 'email.send', []);

        self::assertSame(1, $driver->getPendingCount('default'));
        self::assertSame(0, $driver->getDelayedCount('default'));
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

        self::assertCount(2, $jobIds);
        self::assertSame(2, $driver->getDelayedCount('default'));
        self::assertSame(0, $driver->getPendingCount('default'));
        foreach ($jobIds as $jobId) {
            self::assertSame('2023-11-14 22:14:20', $storage->find($jobId)?->availableAt);
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

        self::assertCount(2, $jobIds);
        self::assertSame(2, $driver->getPendingCount('default'));
        self::assertSame(0, $driver->getDelayedCount('default'));
    }

    public function testCancelScheduledJobRemovesDelayedNotification(): void
    {
        $clock = new FrozenClock();
        [$storage, $driver, $dispatcher] = $this->scheduledServices($clock);

        $jobId = $dispatcher->dispatch('email.send', [], 'default', 3, null, $clock->timestamp() + 60);
        self::assertSame(1, $driver->getDelayedCount('default'));

        self::assertTrue($dispatcher->cancelJob($jobId));

        self::assertSame(0, $driver->getDelayedCount('default'));
        self::assertSame(JobStatus::Cancelled, $storage->find($jobId)?->status);
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
