<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Integration;

use Oeltima\SimpleQueue\Contract\JobStatus;
use Oeltima\SimpleQueue\Contract\QueueDriverInterface;
use Oeltima\SimpleQueue\Contract\SupportsDelayedJobs;
use Oeltima\SimpleQueue\Driver\DatabaseQueueDriver;
use Oeltima\SimpleQueue\Driver\InMemoryQueueDriver;
use Oeltima\SimpleQueue\Driver\RedisQueueDriver;
use Oeltima\SimpleQueue\JobDispatcher;
use Oeltima\SimpleQueue\QueueManager;
use Oeltima\SimpleQueue\Storage\InMemoryJobStorage;
use Oeltima\SimpleQueue\Tests\Support\FrozenClock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Predis\Client;

enum ScheduledDispatchBackend
{
    case Memory;
    case Database;
    case Redis;
}

final class ScheduledDispatchTest extends TestCase
{
    private ?QueueDriverInterface $driver = null;

    /** @return iterable<string, array{ScheduledDispatchBackend}> */
    public static function backends(): iterable
    {
        yield 'in-memory' => [ScheduledDispatchBackend::Memory];
        yield 'database' => [ScheduledDispatchBackend::Database];
        yield 'Redis' => [ScheduledDispatchBackend::Redis];
    }

    protected function tearDown(): void
    {
        if ($this->driver instanceof InMemoryQueueDriver) {
            $this->driver->clear();
        }
        if ($this->driver instanceof RedisQueueDriver) {
            $this->driver->clear('default');
        }
        $this->driver = null;
    }

    #[DataProvider('backends')]
    public function testScheduledJobIsNotClaimableBeforeAvailableAt(ScheduledDispatchBackend $backend): void
    {
        [$clock, $storage, $driver, $dispatcher] = $this->services($backend);
        $jobId = $dispatcher->dispatch('scheduled.job', ['n' => 1], 'default', 3, null, $clock->timestamp() + 60);

        $initial = $storage->find($jobId);
        self::assertNotNull($initial);
        self::assertSame(JobStatus::Pending, $initial->status);

        $before = $storage->claimNextAvailable('default', 'worker-1');
        self::assertNull($before);

        if ($driver instanceof SupportsDelayedJobs) {
            self::assertSame(0, $driver->promoteDelayedJobs('default'));
            $notYetNotified = $driver->dequeue('default', 0);
            self::assertNull($notYetNotified);
        }

        $clock->advance(59);
        $stillPending = $storage->claimNextAvailable('default', 'worker-1');
        self::assertNull($stillPending);

        $clock->advance(1);
        if ($driver instanceof SupportsDelayedJobs) {
            self::assertSame(1, $driver->promoteDelayedJobs('default'));
            self::assertSame($jobId, $driver->dequeue('default', 0));
        }

        $claim = $storage->claimNextAvailable('default', 'worker-1');
        self::assertNotNull($claim);
        self::assertSame($jobId, $claim->job->id);
        $running = $storage->find($jobId);
        self::assertNotNull($running);
        self::assertSame(JobStatus::Running, $running->status);
    }

    #[DataProvider('backends')]
    public function testScheduledInPastOrNowBehavesLikeImmediateDispatch(ScheduledDispatchBackend $backend): void
    {
        [$clock, $storage, $driver, $dispatcher] = $this->services($backend);
        $pastId = $dispatcher->dispatch('scheduled.job', ['n' => 1], 'default', 3, null, $clock->timestamp() - 30);

        $initial = $storage->find($pastId);
        self::assertNotNull($initial);
        self::assertSame(JobStatus::Pending, $initial->status);
        if ($driver instanceof SupportsDelayedJobs) {
            self::assertSame(0, $driver->promoteDelayedJobs('default'));
            self::assertSame($pastId, $driver->dequeue('default', 0));
        }

        $claim = $storage->claimNextAvailable('default', 'worker-1');
        self::assertNotNull($claim);
        self::assertSame($pastId, $claim->job->id);
    }

    #[DataProvider('backends')]
    public function testScheduledBatchBecomesClaimableAfterAvailableAt(ScheduledDispatchBackend $backend): void
    {
        [$clock, $storage, $driver, $dispatcher] = $this->services($backend);
        $jobIds = $dispatcher->dispatchBatch(
            'scheduled.job',
            [['n' => 1], ['n' => 2]],
            'default',
            3,
            $clock->timestamp() + 30
        );

        self::assertCount(2, $jobIds);
        $before = $storage->claimNextAvailable('default', 'worker-1');
        self::assertNull($before);

        $clock->advance(30);
        if ($driver instanceof SupportsDelayedJobs) {
            self::assertSame(2, $driver->promoteDelayedJobs('default'));
        }

        $claimed = [];
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $claim = $storage->claimNextAvailable('default', 'worker-1');
            if ($claim === null) {
                break;
            }
            $claimed[] = $claim->job->id;
        }
        self::assertCount(2, $claimed);
        self::assertEmpty(array_diff($jobIds, $claimed));
    }

    /**
     * Build scheduled-dispatch services that share one frozen clock.
     *
     * @return array{FrozenClock, InMemoryJobStorage, QueueDriverInterface, JobDispatcher}
     */
    private function services(ScheduledDispatchBackend $backend): array
    {
        $clock = new FrozenClock();
        $storage = new InMemoryJobStorage($clock);
        $driver = match ($backend) {
            ScheduledDispatchBackend::Memory => new InMemoryQueueDriver($clock),
            ScheduledDispatchBackend::Database => new DatabaseQueueDriver($storage, 50, $clock),
            ScheduledDispatchBackend::Redis => $this->redisDriver($clock),
        };
        $this->driver = $driver;
        $dispatcher = new JobDispatcher($storage, new QueueManager($driver), $clock);

        return [$clock, $storage, $driver, $dispatcher];
    }

    private function redisDriver(FrozenClock $clock): RedisQueueDriver
    {
        $host = getenv('REDIS_HOST');
        if (!is_string($host) || $host === '') {
            self::markTestSkipped('REDIS_HOST is not set. Skipping Redis scheduled dispatch.');
        }
        $port = getenv('REDIS_PORT') ?: '6379';
        $client = new Client(['scheme' => 'tcp', 'host' => $host, 'port' => (int) $port]);
        try {
            $client->connect();
        } catch (\Throwable $exception) {
            self::markTestSkipped('Could not connect to Redis: ' . $exception->getMessage());
        }

        return new RedisQueueDriver($client, 'sched-' . bin2hex(random_bytes(8)), $clock);
    }
}
