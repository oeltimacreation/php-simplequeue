<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Unit;

use Oeltima\SimpleQueue\Contract\ControlledJobContextInterface;
use Oeltima\SimpleQueue\Contract\JobContextInterface;
use Oeltima\SimpleQueue\Contract\JobHandlerInterface;
use Oeltima\SimpleQueue\Contract\JobMiddlewareInterface;
use Oeltima\SimpleQueue\Contract\JobStatus;
use Oeltima\SimpleQueue\Contract\ClaimedJob;
use Oeltima\SimpleQueue\Driver\InMemoryQueueDriver;
use Oeltima\SimpleQueue\Internal\WorkerExecutionControl;
use Oeltima\SimpleQueue\Internal\WorkerExecutionCallbacks;
use Oeltima\SimpleQueue\JobDispatcher;
use Oeltima\SimpleQueue\JobRegistry;
use Oeltima\SimpleQueue\QueueManager;
use Oeltima\SimpleQueue\Storage\InMemoryJobStorage;
use Oeltima\SimpleQueue\Tests\Support\FrozenClock;
use Oeltima\SimpleQueue\Worker;
use PHPUnit\Framework\TestCase;

final class ExecutionControlTest extends TestCase
{
    public function testCoalescingKeepsLatestValueAndHeartbeatsIndependently(): void
    {
        $clock = new FrozenClock();
        $writes = [];
        $heartbeats = 0;
        $control = new WorkerExecutionControl(
            $clock,
            new WorkerExecutionCallbacks(
                static function (int $percent, ?string $message) use (&$writes): void {
                    $writes[] = [$percent, $message];
                },
                static function () use (&$heartbeats): void {
                    $heartbeats++;
                },
                static function (): void {
                },
            ),
            10,
            2
        );
        $control->reportProgress(0);
        for ($index = 1; $index <= 5; $index++) {
            $clock->advance(1);
            $control->reportProgress($index, 'newest');
        }
        self::assertSame([0], array_column($writes, 0));
        self::assertSame(2, $heartbeats);
        $control->flush();
        $control->flush();
        self::assertSame([[0, null], [5, 'newest']], $writes);
    }

    public function testMiddlewareCanInspectHeartbeatAndReportProgressWithoutClaimSideChannel(): void
    {
        $storage = new InMemoryJobStorage();
        $manager = new QueueManager(new InMemoryQueueDriver());
        $id = (new JobDispatcher($storage, $manager))->dispatch('test', []);
        $registry = new JobRegistry();
        $registry->register('test', ControlTestHandler::class);
        $registry->middleware->register(new class implements JobMiddlewareInterface {
            public function process(JobContextInterface $context): mixed
            {
                TestCase::assertInstanceOf(ControlledJobContextInterface::class, $context);
                TestCase::assertSame($context->getJobId(), $context->getClaim()->job->id);
                TestCase::assertNotSame('', $context->getClaim()->leaseToken);
                $context->assertOwnership();
                $context->heartbeat();
                $context->reportProgress(50, 'Supervised');
                return $context->proceed();
            }
        });
        self::assertTrue((new Worker($storage, $manager, $registry))->processOne());
        self::assertSame(JobStatus::Completed, $storage->find($id)?->status);
    }

    public function testWorkerFlushesFinalProgressAndDoesNotRetryAfterFlushInfrastructureFailure(): void
    {
        foreach ([false, true] as $failFlush) {
            $storage = new ProgressRecordingStorage($failFlush);
            $manager = new QueueManager(new InMemoryQueueDriver());
            $id = (new JobDispatcher($storage, $manager))->dispatch('test', []);
            $registry = new JobRegistry();
            $registry->register('test', ControlTestHandler::class);
            try {
                (new Worker($storage, $manager, $registry, options: ['progress_interval' => 60]))->processOne();
                self::assertFalse($failFlush);
            } catch (\RuntimeException $exception) {
                self::assertTrue($failFlush);
                self::assertSame('Final progress unavailable', $exception->getMessage());
            }
            self::assertSame([1, 100], $storage->writes);
            self::assertSame($failFlush ? JobStatus::Running : JobStatus::Completed, $storage->find($id)?->status);
            self::assertSame(0, $storage->find($id)->attempts);
        }
    }

    public function testOwnershipLossInMiddlewareNeverCompletesOrAcknowledgesOldLease(): void
    {
        $clock = new FrozenClock();
        $storage = new InMemoryJobStorage($clock);
        $driver = new InMemoryQueueDriver($clock);
        $manager = new QueueManager($driver);
        $id = (new JobDispatcher($storage, $manager))->dispatch('test', []);
        $registry = new JobRegistry();
        $registry->register('test', ControlTestHandler::class);
        $registry->middleware->register(new class ($storage, $clock, $id) implements JobMiddlewareInterface {
            public function __construct(private InMemoryJobStorage $storage, private FrozenClock $clock, private int $id)
            {
            }
            public function process(JobContextInterface $context): mixed
            {
                TestCase::assertInstanceOf(ControlledJobContextInterface::class, $context);
                $this->clock->advance(601);
                $this->storage->recoverStaleJobs(600);
                TestCase::assertNotNull($this->storage->claimById($this->id, 'replacement-worker'));
                $context->assertOwnership();
                return $context->proceed();
            }
        });
        self::assertTrue((new Worker($storage, $manager, $registry))->processOne());
        self::assertSame('replacement-worker', $storage->find($id)?->lockedBy);
        self::assertSame([$id], $driver->getProcessing('default'));
    }
}

final class ControlTestHandler implements JobHandlerInterface
{
    public function handle(int $jobId, array $payload, ?callable $progressCallback = null): mixed
    {
        if ($progressCallback !== null) {
            $progressCallback(1);
            $progressCallback(2);
            $progressCallback(100);
        }
        return ['ok' => true];
    }
}

final class ProgressRecordingStorage extends InMemoryJobStorage
{
    /** @var list<int|null> */
    public array $writes = [];

    public function __construct(private readonly bool $failFlush)
    {
        parent::__construct();
    }

    public function updateProgress(ClaimedJob $claim, ?int $progress = null, ?string $message = null): bool
    {
        $this->writes[] = $progress;
        if ($this->failFlush && count($this->writes) === 2) {
            throw new \RuntimeException('Final progress unavailable');
        }
        return parent::updateProgress($claim, $progress, $message);
    }
}
