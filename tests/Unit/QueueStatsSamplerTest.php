<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Unit;

use Oeltima\SimpleQueue\Contract\QueueStatsSnapshot;
use Oeltima\SimpleQueue\Contract\SupportsQueueStatsSnapshot;
use Oeltima\SimpleQueue\Driver\InMemoryQueueDriver;
use Oeltima\SimpleQueue\QueueStatsSampler;
use Oeltima\SimpleQueue\Tests\Support\FrozenClock;
use PHPUnit\Framework\TestCase;

final class QueueStatsSamplerTest extends TestCase
{
    public function testEventVolumeDoesNotAmplifyStatisticsReads(): void
    {
        $clock = new FrozenClock();
        $stats = $this->createMock(SupportsQueueStatsSnapshot::class);
        $stats->expects(self::exactly(2))->method('getStatsSnapshot')->willReturn(new QueueStatsSnapshot(3, 2, 1, $clock->timestamp()));
        $sampler = new QueueStatsSampler($stats, interval: 5, clock: $clock);
        for ($index = 0; $index < 1000; $index++) {
            self::assertSame(3, $sampler->sample()->pending);
        }
        $clock->advance(5);
        self::assertSame(2, $sampler->sample()->processing);
    }

    public function testSnapshotParityForPendingProcessingAndDelayedQueues(): void
    {
        $clock = new FrozenClock();
        $driver = new InMemoryQueueDriver($clock);
        $driver->enqueueBatch('default', [1, 2]);
        $driver->dequeue('default', 0);
        $driver->enqueueDelayed('default', 3, $clock->timestamp() + 10);
        $snapshot = $driver->getStatsSnapshot('default');
        self::assertSame([1, 1, 1, $clock->timestamp()], [$snapshot->pending, $snapshot->processing, $snapshot->delayed, $snapshot->sampledAt]);
    }
}
