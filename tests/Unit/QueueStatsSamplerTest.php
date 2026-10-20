<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Unit;

use Oeltima\SimpleQueue\Contract\QueueStatsSnapshot;
use Oeltima\SimpleQueue\Contract\QueueStatsInterface;
use Oeltima\SimpleQueue\Contract\SupportsQueueStatsSnapshot;
use Oeltima\SimpleQueue\Driver\InMemoryQueueDriver;
use Oeltima\SimpleQueue\QueueStatsSampler;
use Oeltima\SimpleQueue\Tests\Support\FrozenClock;
use PHPUnit\Framework\TestCase;

final class QueueStatsSamplerTest extends TestCase
{
    public function testLegacyStatisticsRetryAfterFailureAndCacheOnlySuccessfulSnapshots(): void
    {
        $stats = $this->createMock(QueueStatsInterface::class);
        $calls = 0;
        $stats->expects(self::exactly(2))->method('getPendingCount')->willReturnCallback(static function () use (&$calls): int {
            if (++$calls === 1) {
                throw new \RuntimeException('Statistics unavailable');
            }
            return 7;
        });
        $stats->expects(self::once())->method('getProcessingCount')->willReturn(2);
        $stats->expects(self::once())->method('getDelayedCount')->willReturn(3);
        $sampler = new QueueStatsSampler($stats);
        try {
            $sampler->sample();
            self::fail('The caller must observe the telemetry failure');
        } catch (\RuntimeException $exception) {
            self::assertSame('Statistics unavailable', $exception->getMessage());
        }
        $snapshot = $sampler->sample();
        self::assertSame([7, 2, 3], [$snapshot->pending, $snapshot->processing, $snapshot->delayed]);
        self::assertSame($snapshot, $sampler->sample());
    }

    public function testInvalidIntervalsCannotDisableTheSamplingBound(): void
    {
        foreach ([0.0, -1.0, INF, NAN] as $interval) {
            try {
                new QueueStatsSampler(new InMemoryQueueDriver(), interval: $interval);
                self::fail('Invalid interval must fail before sampling');
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString('finite and positive', $exception->getMessage());
            }
        }
    }

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
