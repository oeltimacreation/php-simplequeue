<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Integration;

use Oeltima\SimpleQueue\AdminManager;
use Oeltima\SimpleQueue\Contract\JobContextInterface;
use Oeltima\SimpleQueue\Contract\JobHandlerInterface;
use Oeltima\SimpleQueue\Contract\JobMiddlewareInterface;
use Oeltima\SimpleQueue\Contract\JobStatus;
use Oeltima\SimpleQueue\Driver\InMemoryQueueDriver;
use Oeltima\SimpleQueue\JobDispatcher;
use Oeltima\SimpleQueue\JobRegistry;
use Oeltima\SimpleQueue\QueueManager;
use Oeltima\SimpleQueue\Storage\InMemoryJobStorage;
use Oeltima\SimpleQueue\Worker;
use PHPUnit\Framework\TestCase;

final class Stage45SoakTest extends TestCase
{
    public function testMiddlewareWorkerRecyclingKeepsBacklogMemoryBounded(): void
    {
        $storage = new InMemoryJobStorage();
        $driver = new InMemoryQueueDriver();
        $registry = new JobRegistry();
        $registry->register('soak.middleware', Stage45SoakHandler::class);
        $registry->middleware->register(new Stage45SoakMiddleware());
        $dispatcher = new JobDispatcher($storage, new QueueManager($driver));
        $jobIds = $dispatcher->dispatchBatch('soak.middleware', $this->payloads(300));
        $memoryBefore = memory_get_usage(true);

        for ($cycle = 0; $cycle < 6; $cycle++) {
            $worker = new Worker($storage, new QueueManager($driver), $registry, options: [
                'lock_file' => null,
                'poll_timeout' => 0,
            ]);
            for ($job = 0; $job < 50; $job++) {
                self::assertTrue($worker->processOne());
            }
        }

        gc_collect_cycles();
        self::assertCount(300, $jobIds);
        self::assertSame(300, $storage->count(JobStatus::Completed));
        self::assertSame([], $driver->getPending('default'));
        self::assertLessThanOrEqual($memoryBefore + 8_388_608, memory_get_usage(true));
    }

    public function testDeadLetterBacklogCanBePagedAndPurged(): void
    {
        $storage = new InMemoryJobStorage();
        $driver = new InMemoryQueueDriver();
        $admin = new AdminManager($storage, new QueueManager($driver));
        $failedIds = [];
        for ($index = 0; $index < 150; $index++) {
            $jobId = $storage->createJob('soak.failed', []);
            $claim = $storage->claimById($jobId, 'soak-admin');
            self::assertNotNull($claim);
            self::assertTrue($storage->markFailed($claim, 'backlog failure'));
            $failedIds[] = $jobId;
        }

        $listedIds = [];
        for ($offset = 0; $offset < 150; $offset += 25) {
            $listedIds = array_merge($listedIds, array_column($admin->listFailed(limit: 25, offset: $offset), 'id'));
        }
        self::assertSame(array_reverse($failedIds), $listedIds);

        foreach ($listedIds as $jobId) {
            self::assertTrue($admin->purgeFailed($jobId));
        }

        self::assertSame([], $admin->listFailed());
        self::assertSame([], $driver->getPending('default'));
    }

    /**
     * @return list<array{sequence: int}>
     */
    private function payloads(int $count): array
    {
        $payloads = [];
        for ($index = 0; $index < $count; $index++) {
            $payloads[] = ['sequence' => $index];
        }

        return $payloads;
    }
}

final class Stage45SoakHandler implements JobHandlerInterface
{
    public function handle(int $jobId, array $payload, ?callable $progressCallback = null): array
    {
        return ['job_id' => $jobId, 'sequence' => $payload['sequence']];
    }
}

final class Stage45SoakMiddleware implements JobMiddlewareInterface
{
    public function process(JobContextInterface $context): mixed
    {
        return $context->proceed();
    }
}
