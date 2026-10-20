<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Unit;

use Oeltima\SimpleQueue\Contract\QueueDriverInterface;
use Oeltima\SimpleQueue\Contract\SupportsDelayedJobs;
use Oeltima\SimpleQueue\Driver\InMemoryQueueDriver;
use Oeltima\SimpleQueue\JobDispatcher;
use Oeltima\SimpleQueue\MaintenanceOptions;
use Oeltima\SimpleQueue\QueueMaintenance;
use Oeltima\SimpleQueue\QueueManager;
use Oeltima\SimpleQueue\Storage\InMemoryJobStorage;
use Oeltima\SimpleQueue\Tests\Support\FrozenClock;
use PHPUnit\Framework\TestCase;

final class QueueMaintenanceTest extends TestCase
{
    public function testDelayedJobsProgressWhileASeparateHandlerStillOwnsItsLease(): void
    {
        $clock = new FrozenClock();
        $storage = new InMemoryJobStorage($clock);
        $driver = new InMemoryQueueDriver($clock);
        $dispatcher = new JobDispatcher($storage, new QueueManager($driver), $clock);
        $long = $dispatcher->dispatch('long', []);
        self::assertSame($long, $driver->dequeue('default', 0));
        $claim = $storage->claimById($long, 'busy-worker');
        self::assertNotNull($claim);
        $later = $dispatcher->dispatchAfter(10, 'due', []);
        $clock->advance(11);
        self::assertTrue($storage->heartbeat($claim));
        $driver->heartbeatProcessing('default', $long);
        $result = (new QueueMaintenance($storage, $driver, $clock))->run('default');
        self::assertSame(1, $result->promoted);
        self::assertSame([$later], $driver->getPending('default'));
        self::assertSame([$long], $driver->getProcessing('default'));
        self::assertTrue($storage->isClaimOwned($claim));
    }

    public function testCallerOwnedCursorCoversMissingNotificationsInBoundedPages(): void
    {
        $clock = new FrozenClock();
        $storage = new InMemoryJobStorage($clock);
        $driver = new InMemoryQueueDriver($clock);
        for ($index = 0; $index < 5; $index++) {
            $storage->createJob('test', []);
        }
        $maintenance = new QueueMaintenance($storage, $driver, $clock);
        $cursor = null;
        $restored = 0;
        for ($pass = 0; $pass < 3; $pass++) {
            $result = $maintenance->run('default', new MaintenanceOptions(pageSize: 2), $cursor);
            self::assertNotNull($result->reconciliation);
            self::assertLessThanOrEqual(2, $result->reconciliation->scanned);
            $restored += $result->reconciliation->restored;
            $cursor = $result->nextCursor;
        }
        self::assertSame(5, $restored);
        self::assertNull($cursor);
        self::assertCount(5, $driver->getPending('default'));
    }

    public function testPassDeadlineDefersLaterPhasesAndRetainsCursor(): void
    {
        $clock = new FrozenClock();
        $driver = $this->createMockForIntersectionOfInterfaces([QueueDriverInterface::class, SupportsDelayedJobs::class]);
        $driver->method('promoteDelayedJobs')->willReturnCallback(static function () use ($clock): int {
            $clock->advance(2);
            return 1;
        });
        $result = (new QueueMaintenance(new InMemoryJobStorage($clock), $driver, $clock))->run('default', cursor: 7);
        self::assertSame(1, $result->promoted);
        self::assertFalse($result->recoveryRan);
        self::assertFalse($result->reconciliationRan);
        self::assertSame(7, $result->nextCursor);
    }
}
