<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Integration;

use Oeltima\SimpleQueue\Contract\ClaimedJob;
use Oeltima\SimpleQueue\Contract\JobHandlerInterface;
use Oeltima\SimpleQueue\Contract\JobStatus;
use Oeltima\SimpleQueue\Contract\QueueDriverInterface;
use Oeltima\SimpleQueue\Contract\SupportsBoundedQueueMembership;
use Oeltima\SimpleQueue\Contract\SupportsJobRemoval;
use Oeltima\SimpleQueue\Driver\InMemoryQueueDriver;
use Oeltima\SimpleQueue\Exception\QueueException;
use Oeltima\SimpleQueue\JobDispatcher;
use Oeltima\SimpleQueue\JobRegistry;
use Oeltima\SimpleQueue\QueueManager;
use Oeltima\SimpleQueue\QueueReconciler;
use Oeltima\SimpleQueue\ReconcileOptions;
use Oeltima\SimpleQueue\Storage\InMemoryJobStorage;
use Oeltima\SimpleQueue\Tests\Support\FrozenClock;
use Oeltima\SimpleQueue\Worker;
use PHPUnit\Framework\TestCase;

enum QueueFailureOperation: string
{
    case Enqueue = 'enqueue';
    case Acknowledge = 'ack';
    case Reject = 'nack';
    case Remove = 'remove';
}

final class QueueFailurePlan
{
    private bool $pending = true;

    public function __construct(private readonly QueueFailureOperation $failure)
    {
    }

    public function run(QueueFailureOperation $operation, \Closure $delegate): mixed
    {
        if ($operation !== $this->failure) {
            return $delegate();
        }
        if (!$this->pending) {
            return $delegate();
        }
        $this->pending = false;
        throw new \RuntimeException('Injected ' . $operation->value . ' failure');
    }
}

final class FailurePathTest extends TestCase
{
    public function testStorageFailureBeforeDispatchLeavesNoNotification(): void
    {
        $storage = new class extends InMemoryJobStorage {
            public function createJob(
                string $type,
                array $payload,
                string $queue = 'default',
                int $maxAttempts = 3,
                ?string $requestId = null
            ): int {
                throw new \RuntimeException('Injected storage failure');
            }
        };
        $driver = new InMemoryQueueDriver();
        $dispatcher = new JobDispatcher($storage, new QueueManager($driver));

        try {
            $dispatcher->dispatch('test.job', ['secret' => 'not-queued']);
            self::fail('Storage failure must escape dispatch');
        } catch (\RuntimeException $exception) {
            self::assertSame('Injected storage failure', $exception->getMessage());
        }

        self::assertSame([], $storage->list());
        self::assertSame([], $driver->getPending('default'));
    }

    public function testNotificationFailureLeavesPendingJobForReconciliation(): void
    {
        $storage = new InMemoryJobStorage();
        $inner = new InMemoryQueueDriver();
        $driver = $this->faultInjectingDriver($inner, QueueFailureOperation::Enqueue);
        $dispatcher = new JobDispatcher($storage, new QueueManager($driver));

        try {
            $dispatcher->dispatch('test.job', ['important' => true]);
            self::fail('Notification failure must escape dispatch');
        } catch (\RuntimeException $exception) {
            self::assertSame('Injected enqueue failure', $exception->getMessage());
        }

        $jobs = $storage->list(JobStatus::Pending);
        self::assertCount(1, $jobs);
        self::assertSame([], $inner->getPending('default'));
        $result = (new QueueReconciler($storage, $driver))->reconcile('default', new ReconcileOptions());
        self::assertSame(1, $result->restored);
        self::assertSame([$jobs[0]->id], $inner->getPending('default'));
    }

    public function testAckFailureRecoversTerminalDuplicateWithoutReexecution(): void
    {
        $clock = new FrozenClock();
        $storage = new InMemoryJobStorage($clock);
        $inner = new InMemoryQueueDriver($clock);
        $driver = $this->faultInjectingDriver($inner, QueueFailureOperation::Acknowledge);
        [$dispatcher, $worker] = $this->successfulWorker($storage, $driver);
        $jobId = $dispatcher->dispatch('test.success', []);

        self::assertTrue($worker->processOne());
        self::assertSame(JobStatus::Completed, $storage->find($jobId)->status);
        self::assertSame([$jobId], $inner->getProcessing('default'));

        $clock->advance(61);
        self::assertSame(1, $inner->recoverStaleProcessing('default', 60));
        self::assertFalse($worker->processOne());
        self::assertSame(JobStatus::Completed, $storage->find($jobId)?->status);
        self::assertSame([], $inner->getProcessing('default'));
    }

    public function testNackFailureLeavesRetryDurableUntilQueueRecovery(): void
    {
        $clock = new FrozenClock();
        $storage = new InMemoryJobStorage($clock);
        $inner = new InMemoryQueueDriver($clock);
        $driver = $this->faultInjectingDriver($inner, QueueFailureOperation::Reject);
        [$jobId, $worker] = $this->retryJob($storage, $driver);

        self::assertTrue($worker->processOne());
        $job = $storage->find($jobId);
        self::assertSame(JobStatus::Pending, $job?->status);
        self::assertSame(1, $job->attempts);
        self::assertSame([$jobId], $inner->getProcessing('default'));
        self::assertSame([], $inner->getDelayed('default'));

        $clock->advance(61);
        self::assertSame(1, $inner->recoverStaleProcessing('default', 60));
        self::assertSame($jobId, $inner->dequeue('default', 0));
        self::assertNotNull($storage->claimById($jobId, 'recovery-worker'));
    }

    public function testRetryStorageFailureLeavesClaimForStaleRecovery(): void
    {
        $clock = new FrozenClock();
        $storage = new class ($clock) extends InMemoryJobStorage {
            public function scheduleRetry(
                ClaimedJob $claim,
                int $attempts,
                int $delaySeconds,
                ?string $errorMessage = null
            ): bool {
                throw new \RuntimeException('Injected retry storage failure');
            }
        };
        $driver = new InMemoryQueueDriver($clock);
        [$jobId, $worker] = $this->retryJob($storage, $driver);

        self::assertTrue($worker->processOne());
        self::assertSame(JobStatus::Running, $storage->find($jobId)?->status);
        self::assertSame([$jobId], $driver->getProcessing('default'));

        $clock->advance(61);
        self::assertSame(1, $storage->recoverStaleJobs(60));
        self::assertSame(1, $driver->recoverStaleProcessing('default', 60));
        self::assertSame(JobStatus::Pending, $storage->find($jobId)->status);
    }

    public function testResultSerializationFailureIsTerminalAndAcknowledged(): void
    {
        $storage = new InMemoryJobStorage();
        $driver = new InMemoryQueueDriver();
        $registry = new JobRegistry();
        $handler = new class implements JobHandlerInterface {
            public function handle(int $jobId, array $payload, ?callable $progressCallback = null): float
            {
                return NAN;
            }
        };
        $registry->register('test.serialize', $handler::class);
        $dispatcher = new JobDispatcher($storage, new QueueManager($driver));
        $jobId = $dispatcher->dispatch('test.serialize', []);

        self::assertTrue($this->worker($storage, $driver, $registry)->processOne());

        $job = $storage->find($jobId);
        self::assertSame(JobStatus::Failed, $job?->status);
        self::assertSame('Unable to encode job result as JSON', $job->errorMessage);
        self::assertSame([], $driver->getProcessing('default'));
        self::assertSame([], $driver->getPending('default'));
    }

    public function testDuplicateDeliveryExecutesHandlerOnlyOnce(): void
    {
        $storage = new InMemoryJobStorage();
        $driver = new InMemoryQueueDriver();
        $registry = new JobRegistry();
        $handler = new class implements JobHandlerInterface {
            public static int $calls = 0;

            public function handle(int $jobId, array $payload, ?callable $progressCallback = null): bool
            {
                self::$calls++;
                return true;
            }
        };
        $handler::$calls = 0;
        $registry->register('test.duplicate', $handler::class);
        $dispatcher = new JobDispatcher($storage, new QueueManager($driver));
        $jobId = $dispatcher->dispatch('test.duplicate', []);
        $driver->enqueue('default', $jobId);
        $worker = $this->worker($storage, $driver, $registry);

        self::assertTrue($worker->processOne());
        self::assertFalse($worker->processOne());
        self::assertSame(1, $handler::$calls);
        self::assertSame(JobStatus::Completed, $storage->find($jobId)?->status);
        self::assertSame([], $driver->getProcessing('default'));
    }

    public function testCleanupFailureKeepsCancellationDurableAndRetryable(): void
    {
        $storage = new InMemoryJobStorage();
        $inner = new InMemoryQueueDriver();
        $driver = $this->faultInjectingDriver($inner, QueueFailureOperation::Remove);
        $dispatcher = new JobDispatcher($storage, new QueueManager($driver));
        $jobId = $dispatcher->dispatch('test.cancel', []);

        try {
            $dispatcher->cancelJob($jobId);
            self::fail('Cleanup failure must be reported');
        } catch (QueueException $exception) {
            self::assertSame('Job was cancelled but queue notification cleanup failed', $exception->getMessage());
        }

        self::assertSame(JobStatus::Cancelled, $storage->find($jobId)?->status);
        self::assertSame([$jobId], $inner->getPending('default'));
        self::assertFalse($dispatcher->cancelJob($jobId));
        self::assertSame([], $inner->getPending('default'));
    }

    /**
     * @return QueueDriverInterface&SupportsBoundedQueueMembership&SupportsJobRemoval&\PHPUnit\Framework\MockObject\MockObject
     */
    private function faultInjectingDriver(
        InMemoryQueueDriver $inner,
        QueueFailureOperation $failure
    ): QueueDriverInterface {
        $driver = $this->createMockForIntersectionOfInterfaces([
            QueueDriverInterface::class,
            SupportsBoundedQueueMembership::class,
            SupportsJobRemoval::class,
        ]);
        $plan = new QueueFailurePlan($failure);
        $driver->method('isAvailable')->willReturnCallback($inner->isAvailable(...));
        $driver->method('dequeue')->willReturnCallback($inner->dequeue(...));
        $driver->method('hasPendingJob')->willReturnCallback($inner->hasPendingJob(...));
        $driver->method('hasDelayedJob')->willReturnCallback($inner->hasDelayedJob(...));
        $faultableOperations = [
            'enqueue' => [QueueFailureOperation::Enqueue, $inner->enqueue(...)],
            'ack' => [QueueFailureOperation::Acknowledge, $inner->ack(...)],
            'nack' => [QueueFailureOperation::Reject, $inner->nack(...)],
            'remove' => [QueueFailureOperation::Remove, $inner->remove(...)],
        ];
        foreach ($faultableOperations as $method => [$operation, $delegate]) {
            $driver->method($method)->willReturnCallback(
                static fn(mixed ...$arguments): mixed => $plan->run(
                    $operation,
                    static fn(): mixed => call_user_func_array($delegate, $arguments)
                )
            );
        }
        return $driver;
    }

    /** @return array{JobDispatcher, Worker} */
    private function successfulWorker(
        InMemoryJobStorage $storage,
        QueueDriverInterface $driver
    ): array {
        $registry = new JobRegistry();
        $handler = new class implements JobHandlerInterface {
            public function handle(int $jobId, array $payload, ?callable $progressCallback = null): bool
            {
                return true;
            }
        };
        $registry->register('test.success', $handler::class);
        return [
            new JobDispatcher($storage, new QueueManager($driver)),
            $this->worker($storage, $driver, $registry),
        ];
    }

    private function worker(
        InMemoryJobStorage $storage,
        \Oeltima\SimpleQueue\Contract\QueueDriverInterface $driver,
        JobRegistry $registry
    ): Worker {
        return new Worker($storage, new QueueManager($driver), $registry, options: [
            'lock_file' => null,
            'poll_timeout' => 0,
            'stuck_job_ttl' => 60,
            'retry_base_delay' => 1,
            'retry_max_delay' => 1,
        ]);
    }

    private function failingRegistry(): JobRegistry
    {
        $registry = new JobRegistry();
        $handler = new class implements JobHandlerInterface {
            public function handle(int $jobId, array $payload, ?callable $progressCallback = null): never
            {
                throw new \RuntimeException('Temporary failure');
            }
        };
        $registry->register('test.retry', $handler::class);

        return $registry;
    }

    /** @return array{int, Worker} */
    private function retryJob(
        InMemoryJobStorage $storage,
        \Oeltima\SimpleQueue\Contract\QueueDriverInterface $driver
    ): array {
        $dispatcher = new JobDispatcher($storage, new QueueManager($driver));

        return [
            $dispatcher->dispatch('test.retry', []),
            $this->worker($storage, $driver, $this->failingRegistry()),
        ];
    }
}
