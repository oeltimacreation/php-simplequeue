<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Unit;

use Oeltima\SimpleQueue\Contract\JobHandlerInterface;
use Oeltima\SimpleQueue\Driver\DatabaseQueueDriver;
use Oeltima\SimpleQueue\Driver\InMemoryQueueDriver;
use Oeltima\SimpleQueue\JobDispatcher;
use Oeltima\SimpleQueue\JobRegistry;
use Oeltima\SimpleQueue\QueueManager;
use Oeltima\SimpleQueue\Storage\InMemoryJobStorage;
use Oeltima\SimpleQueue\Tests\Support\FrozenClock;
use Oeltima\SimpleQueue\Worker;
use PHPUnit\Framework\TestCase;

/**
 * Worker effect matrix: standard and claimed dequeue pass the same ordered
 * state/event/notifier assertions across handler/storage/notifier/listener
 * boundaries.
 */
final class WorkerEffectMatrixTest extends TestCase
{
    public function testStandardAndClaimedDequeueShareEventOrder(): void
    {
        foreach (['standard', 'claimed'] as $mode) {
            $clock = new FrozenClock();
            $storage = new InMemoryJobStorage($clock);
            $driver = $mode === 'claimed'
                ? new DatabaseQueueDriver($storage, 250, $clock)
                : new InMemoryQueueDriver($clock);
            $registry = new JobRegistry();
            $handler = new class implements JobHandlerInterface {
                public function handle(int $jobId, array $payload, ?callable $progress = null): mixed
                {
                    return ['ok' => true];
                }
            };
            $registry->register('test.job', get_class($handler));
            $events = [];
            $worker = new Worker($storage, new QueueManager($driver), $registry, null, 'default', [
                'stop_when_empty' => true,
                'event_listener' => function (string $name, array $payload) use (&$events): void {
                    $events[] = $name;
                },
            ]);
            $dispatcher = new JobDispatcher($storage, new QueueManager($driver), $clock);
            $jobId = $dispatcher->dispatch('test.job', []);
            self::assertTrue($worker->processOne());
            self::assertSame(['claimed', 'completed'], $events);
            self::assertSame('completed', $storage->find($jobId)?->status->value);
        }
    }

    public function testRetryEmitsClaimedThenRetried(): void
    {
        $clock = new FrozenClock();
        $storage = new InMemoryJobStorage($clock);
        $driver = new InMemoryQueueDriver($clock);
        $registry = new JobRegistry();
        $handler = new class implements JobHandlerInterface {
            public function handle(int $jobId, array $payload, ?callable $progress = null): mixed
            {
                throw new \RuntimeException('boom');
            }
        };
        $registry->register('test.fail', get_class($handler));
        $events = [];
        $worker = new Worker($storage, new QueueManager($driver), $registry, null, 'default', [
            'event_listener' => function (string $name) use (&$events): void {
                $events[] = $name;
            },
        ]);
        $dispatcher = new JobDispatcher($storage, new QueueManager($driver), $clock);
        $dispatcher->dispatch('test.fail', [], 'default', 3);
        self::assertTrue($worker->processOne());
        self::assertSame(['claimed', 'retried'], $events);
    }

    public function testFirstAttemptShutdownLeavesPendingWithZeroAttempts(): void
    {
        $clock = new FrozenClock();
        $storage = new InMemoryJobStorage($clock);
        $driver = new InMemoryQueueDriver($clock);
        $registry = new JobRegistry();
        $handler = new class implements JobHandlerInterface {
            public function handle(int $jobId, array $payload, ?callable $progress = null): mixed
            {
                return null;
            }
        };
        $registry->register('test.job', get_class($handler));
        $dispatcher = new JobDispatcher($storage, new QueueManager($driver), $clock);
        $jobId = $dispatcher->dispatch('test.job', []);
        $claim = $storage->claimById($jobId, 'worker-1');
        self::assertNotNull($claim);
        self::assertTrue($storage->scheduleRetry($claim, 0, 0, 'Worker shutting down'));
        $job = $storage->find($jobId);
        self::assertNotNull($job);
        self::assertSame('pending', $job->status->value);
        self::assertSame(0, $job->attempts);
    }
}
