<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Integration;

use Oeltima\SimpleQueue\AdminManager;
use Oeltima\SimpleQueue\Contract\JobContextInterface;
use Oeltima\SimpleQueue\Contract\JobHandlerInterface;
use Oeltima\SimpleQueue\Contract\JobMiddlewareInterface;
use Oeltima\SimpleQueue\Contract\JobStatus;
use Oeltima\SimpleQueue\Contract\QueueDriverInterface;
use Oeltima\SimpleQueue\Contract\SupportsJobRemoval;
use Oeltima\SimpleQueue\Driver\InMemoryQueueDriver;
use Oeltima\SimpleQueue\JobDispatcher;
use Oeltima\SimpleQueue\JobRegistry;
use Oeltima\SimpleQueue\QueueManager;
use Oeltima\SimpleQueue\Storage\InMemoryJobStorage;
use Oeltima\SimpleQueue\Tests\Support\FrozenClock;
use Oeltima\SimpleQueue\Worker;
use PHPUnit\Framework\TestCase;

final class Stage45FaultInjectionTest extends TestCase
{
    public function testMiddlewareFailureUsesRetryAndDeadLetterPaths(): void
    {
        Stage45FaultHandler::$calls = 0;
        $clock = new FrozenClock();
        $storage = new InMemoryJobStorage($clock);
        $driver = new InMemoryQueueDriver($clock);
        $registry = new JobRegistry();
        $registry->register('fault.middleware', Stage45FaultHandler::class);
        $registry->middleware->register(new Stage45ThrowingMiddleware());
        $dispatcher = new JobDispatcher($storage, new QueueManager($driver), $clock);
        $jobId = $dispatcher->dispatch('fault.middleware', [], maxAttempts: 2);
        $worker = new Worker($storage, new QueueManager($driver), $registry, options: [
            'lock_file' => null,
            'poll_timeout' => 0,
            'retry_base_delay' => 1,
            'retry_max_delay' => 1,
            'clock' => $clock,
        ]);

        self::assertTrue($worker->processOne());
        $retryJob = $storage->find($jobId);
        self::assertNotNull($retryJob);
        self::assertSame(JobStatus::Pending, $retryJob->status);
        self::assertSame(1, $retryJob->attempts);

        $clock->advance(1);
        self::assertTrue($worker->processOne());
        $failedJob = $storage->find($jobId);
        self::assertNotNull($failedJob);
        self::assertSame(JobStatus::Failed, $failedJob->status);
        self::assertSame([], $driver->getPending('default'));
        self::assertSame([], $driver->getProcessing('default'));
        self::assertSame(0, Stage45FaultHandler::$calls);
    }

    public function testFailedJobPromotionCanRaceAClaimWithoutDuplicateExecution(): void
    {
        $clock = new FrozenClock();
        $storage = new InMemoryJobStorage($clock);
        $inner = new InMemoryQueueDriver($clock);
        $claimed = null;
        $driver = $this->racingDriver($inner, $storage, $claimed);
        $jobId = $this->failedJob($storage);
        $admin = new AdminManager($storage, new QueueManager($driver));

        self::assertTrue($admin->requeueFailed($jobId));
        self::assertNotNull($claimed);
        $runningJob = $storage->find($jobId);
        self::assertNotNull($runningJob);
        self::assertSame(JobStatus::Running, $runningJob->status);
        self::assertTrue($storage->markCompleted($claimed, ['raced' => true]));
        $driver->ack('default', $jobId);

        $completedJob = $storage->find($jobId);
        self::assertNotNull($completedJob);
        self::assertSame(JobStatus::Completed, $completedJob->status);
        self::assertSame([], $inner->getPending('default'));
        self::assertSame([], $inner->getProcessing('default'));
    }

    public function testEventListenerFailureDoesNotPreventCompletion(): void
    {
        $storage = new InMemoryJobStorage();
        $driver = new InMemoryQueueDriver();
        $registry = new JobRegistry();
        $registry->register('fault.listener', Stage45FaultHandler::class);
        $dispatcher = new JobDispatcher($storage, new QueueManager($driver));
        $jobId = $dispatcher->dispatch('fault.listener', []);
        $worker = new Worker($storage, new QueueManager($driver), $registry, options: [
            'lock_file' => null,
            'poll_timeout' => 0,
            'event_listener' => static function (string $event, array $data): void {
                throw new \RuntimeException('listener failure');
            },
        ]);

        self::assertTrue($worker->processOne());
        self::assertSame(JobStatus::Completed, $storage->find($jobId)?->status);
        self::assertSame([], $driver->getProcessing('default'));
    }

    /**
     * @param InMemoryQueueDriver $inner
     * @param InMemoryJobStorage $storage
     * @param \Oeltima\SimpleQueue\Contract\ClaimedJob|null $claimed
     * @return QueueDriverInterface&SupportsJobRemoval
     */
    private function racingDriver(
        InMemoryQueueDriver $inner,
        InMemoryJobStorage $storage,
        ?\Oeltima\SimpleQueue\Contract\ClaimedJob &$claimed
    ): QueueDriverInterface {
        $driver = $this->createMockForIntersectionOfInterfaces([
            QueueDriverInterface::class,
            SupportsJobRemoval::class,
        ]);
        $driver->method('isAvailable')->willReturn(true);
        $driver->method('enqueue')->willReturnCallback(
            function (string $queue, int $jobId) use ($inner, $storage, &$claimed): void {
                $inner->enqueue($queue, $jobId);
                self::assertSame($jobId, $inner->dequeue($queue, 0));
                $claimed = $storage->claimById($jobId, 'racing-worker');
            }
        );
        $driver->method('ack')->willReturnCallback($inner->ack(...));

        return $driver;
    }

    private function failedJob(InMemoryJobStorage $storage): int
    {
        $jobId = $storage->createJob('fault.failed', []);
        $claim = $storage->claimById($jobId, 'fault-worker');
        self::assertNotNull($claim);
        self::assertTrue($storage->markFailed($claim, 'permanent failure'));

        return $jobId;
    }
}

final class Stage45FaultHandler implements JobHandlerInterface
{
    public static int $calls = 0;

    public function handle(int $jobId, array $payload, ?callable $progressCallback = null): bool
    {
        self::$calls++;

        return true;
    }
}

final class Stage45ThrowingMiddleware implements JobMiddlewareInterface
{
    public function process(JobContextInterface $context): never
    {
        throw new \RuntimeException('middleware fault');
    }
}
