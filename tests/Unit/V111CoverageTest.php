<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Unit;

use Oeltima\SimpleQueue\Contract\JobHandlerInterface;
use Oeltima\SimpleQueue\Driver\DatabaseQueueDriver;
use Oeltima\SimpleQueue\Driver\InMemoryQueueDriver;
use Oeltima\SimpleQueue\Internal\JobDataHydrator;
use Oeltima\SimpleQueue\Internal\JobStorageRules;
use Oeltima\SimpleQueue\JobDispatcher;
use Oeltima\SimpleQueue\JobRegistry;
use Oeltima\SimpleQueue\QueueManager;
use Oeltima\SimpleQueue\QueueReconciler;
use Oeltima\SimpleQueue\ReconcileOptions;
use Oeltima\SimpleQueue\Storage\InMemoryJobStorage;
use Oeltima\SimpleQueue\SystemSleeper;
use Oeltima\SimpleQueue\Tests\Support\FrozenClock;
use Oeltima\SimpleQueue\Tests\Support\SqliteFixture;
use Oeltima\SimpleQueue\Worker;
use Oeltima\SimpleQueue\WorkerOptions;
use PHPUnit\Framework\TestCase;

/**
 * v1.11 coverage for corrected behavior (storage, worker, drivers, reconciler).
 */
final class V111CoverageTest extends TestCase
{
    public function testTableAndBoundedValidation(): void
    {
        self::assertSame('jobs', JobStorageRules::validateTableName('jobs'));
        self::assertSame('app.jobs', JobStorageRules::validateTableName('app.jobs'));
        foreach (['', 'a.b.c', 'jobs;drop', 'has space', '"q"'] as $bad) {
            try {
                JobStorageRules::validateTableName($bad);
                self::fail('Bad table must fail: ' . $bad);
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertSame(5, JobStorageRules::validatePositiveId(5));
        self::assertSame('q', JobStorageRules::validateQueueOrType('q', 'Queue'));
        self::assertSame(3, JobStorageRules::validateMaxAttempts(3));
        self::assertSame(0, JobStorageRules::validateNonNegative(0, 'Retention days'));
        self::assertSame(10, JobStorageRules::validatePositiveLimit(10, 'List limit'));
        foreach (
            [
            fn () => JobStorageRules::validatePositiveId(0),
            fn () => JobStorageRules::validateQueueOrType('', 'Queue'),
            fn () => JobStorageRules::validateQueueOrType(str_repeat('x', 256), 'Queue'),
            fn () => JobStorageRules::validateMaxAttempts(0),
            fn () => JobStorageRules::validateNonNegative(-1, 'Retention days'),
            fn () => JobStorageRules::validatePositiveLimit(0),
            ] as $fn
        ) {
            try {
                $fn();
                self::fail('Validation must fail');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testStrictHydratorRejectsCorruptRows(): void
    {
        $base = [
            'id' => 1, 'queue' => 'default', 'type' => 't', 'status' => 'pending',
            'payload' => '[]', 'attempts' => 0, 'max_attempts' => 3,
            'available_at' => '2023-11-14 22:13:20', 'created_at' => '2023-11-14 22:13:20',
            'updated_at' => '2023-11-14 22:13:20', 'started_at' => null, 'completed_at' => null,
            'locked_by' => null, 'locked_at' => null, 'lease_token' => null,
            'error_message' => null, 'error_trace' => null, 'progress' => null,
            'progress_message' => null, 'result' => null, 'request_id' => null,
        ];
        self::assertSame(1, JobDataHydrator::hydrateStrict($base)->id);
        foreach (array_keys($base) as $missingField) {
            $missing = $base;
            unset($missing[$missingField]);
            try {
                JobDataHydrator::hydrateStrict($missing);
                self::fail('Missing durable field must fail: ' . $missingField);
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('"' . $missingField . '"', $exception->getMessage());
            }
        }
        foreach (
            [
            ['id' => 0], ['id' => '1.5'], ['queue' => ''], ['queue' => str_repeat('q', 256)],
            ['type' => ''], ['status' => 'nope'], ['payload' => null], ['attempts' => -1],
            ['attempts' => '1.5'], ['attempts' => 4], ['max_attempts' => 0],
            ['available_at' => ['bad']], ['started_at' => 1], ['progress' => '1.5'],
            ['progress' => 101], ['result' => []], ['locked_by' => 'unexpected'],
            ] as $override
        ) {
            try {
                JobDataHydrator::hydrateStrict(array_merge($base, $override));
                self::fail('Corrupt row must fail');
            } catch (\RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
        // Permissive fromRaw retained for BC.
        self::assertSame(0, \Oeltima\SimpleQueue\Contract\JobData::fromRaw([])->id);
    }

    public function testJobDataCanRetryTerminal(): void
    {
        $storage = new InMemoryJobStorage(new FrozenClock());
        $id = $storage->createJob('t', [], 'default', 1);
        $claim = $storage->claimById($id, 'w');
        self::assertNotNull($claim);
        self::assertTrue($claim->job->canRetry());
        self::assertSame(1, $claim->job->currentAttempt());
        self::assertTrue($storage->markFailed($claim, 'dead'));
        $failed = $storage->find($id);
        self::assertNotNull($failed);
        self::assertFalse($failed->canRetry());
        self::assertTrue($failed->isFinished());
    }

    public function testSleeperValidation(): void
    {
        $sleeper = new SystemSleeper();
        $sleeper->sleep(0.0);
        foreach ([-1.0, INF, NAN] as $bad) {
            try {
                $sleeper->sleep($bad);
                self::fail('Bad sleep must fail');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testPdoBatchChunkingAndExactIds(): void
    {
        $storage = SqliteFixture::createStorage();
        $defs = [];
        for ($i = 0; $i < 250; $i++) {
            $defs[] = ['type' => 't', 'payload' => ['i' => $i]];
        }
        $ids = $storage->createJobs($defs);
        self::assertCount(250, $ids);
        self::assertSame(range($ids[0], $ids[0] + 249), $ids);
        self::assertSame(250, $storage->count());
        $notes = $storage->scanPendingNotifications('default', null, 10);
        self::assertCount(10, $notes);
        self::assertSame($ids[0], $notes[0]->jobId);
        $storage->pruneCompleted(0);
    }

    public function testPdoListCountPruneValidation(): void
    {
        $storage = SqliteFixture::createStorage();
        $storage->createJob('t', []);
        self::assertCount(1, $storage->list(null, null, 10, 0));
        self::assertSame(1, $storage->count());
        foreach (
            [
            fn () => $storage->list(null, null, 0),
            fn () => $storage->list(null, null, 10, -1),
            fn () => $storage->scanPending('default', null, 0),
            fn () => $storage->scanPendingNotifications('default', null, 0),
            fn () => $storage->pruneCompleted(-1),
            fn () => $storage->cancel(0),
            fn () => $storage->find(0),
            ] as $fn
        ) {
            try {
                $fn();
                self::fail('Validation must fail');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertFalse($storage->cancel(999999));
    }

    public function testInMemoryBatchAtomicAndLeanCursor(): void
    {
        $storage = new InMemoryJobStorage(new FrozenClock());
        $before = $storage->count();
        try {
            $storage->createJobs([['type' => 't', 'payload' => []], ['type' => '', 'payload' => []]]);
            self::fail('Invalid batch must fail');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        self::assertSame($before, $storage->count());
        $ids = $storage->createJobs([
            ['type' => 'a', 'payload' => []],
            ['type' => 'b', 'payload' => [], 'queue' => 'q2'],
        ]);
        self::assertCount(2, $ids);
        self::assertCount(1, $storage->scanPendingNotifications('q2', null, 10));
        self::assertCount(0, $storage->scanPendingNotifications('q2', $ids[1], 10));
    }

    public function testInMemoryQueueOBatchesAndEarliestPromotion(): void
    {
        $clock = new FrozenClock();
        $driver = new InMemoryQueueDriver($clock);
        $ids = range(1, 5000);
        $driver->enqueueBatch('q', $ids);
        self::assertSame(5000, $driver->getPendingCount('q'));
        for ($i = 0; $i < 2000; $i++) {
            self::assertSame($ids[$i], $driver->dequeue('q', 0));
        }
        self::assertSame(3000, $driver->getPendingCount('q'));
        // Earliest availability first.
        $driver->enqueueDelayed('q', 9001, $clock->timestamp() + 100);
        $driver->enqueueDelayed('q', 9002, $clock->timestamp() + 10);
        $clock->advance(20);
        self::assertSame(1, $driver->promoteDelayedJobs('q', 1));
        self::assertContains(9002, $driver->getPending('q'));
        // Batch validation atomic.
        try {
            $driver->enqueueBatch('q', [1, 0]);
            self::fail('Invalid batch must fail');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        // Batch reconciliation parity.
        $present = $driver->reconcileNotifications('q', [9001 => $clock->timestamp() + 100], $clock->timestamp(), 100);
        self::assertSame([9001], $present);
        self::assertTrue($driver->hasDelayedJob('q', 9001));
    }

    public function testWorkerIdsDistinctAndLocks(): void
    {
        $storage = new InMemoryJobStorage();
        $driver = new InMemoryQueueDriver();
        $registry = new JobRegistry();
        $w1 = new Worker($storage, new QueueManager($driver), $registry, null, 'default', []);
        $w2 = new Worker($storage, new QueueManager($driver), $registry, null, 'default', []);
        self::assertNotSame($w1->getWorkerId(), $w2->getWorkerId());
        self::assertLessThanOrEqual(255, strlen($w1->getWorkerId()));
        // Disabled locking.
        $disabled = new Worker($storage, new QueueManager($driver), $registry, null, 'default', ['lock_file' => null]);
        $ref = new \ReflectionProperty(Worker::class, 'lockFile');
        self::assertNull($ref->getValue($disabled));
        // Execution guard.
        $worker = new Worker($storage, new QueueManager($driver), $registry, null, 'default', []);
        $prop = new \ReflectionProperty(Worker::class, 'executing');
        $prop->setValue($worker, true);
        try {
            $worker->run();
            self::fail('Re-entrant run must fail');
        } catch (\LogicException) {
            $this->addToAssertionCount(1);
        }
        try {
            $worker->processOne();
            self::fail('Re-entrant processOne must fail');
        } catch (\LogicException) {
            $this->addToAssertionCount(1);
        }
        $prop->setValue($worker, false);
    }

    public function testWorkerTypedOptionsFactoryPreservesPublicConstructionPath(): void
    {
        $worker = Worker::withOptions(
            new InMemoryJobStorage(),
            new QueueManager(new InMemoryQueueDriver()),
            new JobRegistry(),
            new WorkerOptions(lockingEnabled: false)
        );

        self::assertNotSame('', $worker->getWorkerId());
    }

    public function testScheduledPreflightThrowsBeforeMutation(): void
    {
        $storage = new InMemoryJobStorage(new FrozenClock(1700000000));
        $plain = new class implements \Oeltima\SimpleQueue\Contract\QueueDriverInterface {
            public function isAvailable(): bool
            {
                return true;
            }
            public function enqueue(string $queue, int $jobId): void
            {
            }
            public function dequeue(string $queue, int $timeoutSeconds): ?int
            {
                return null;
            }
            public function ack(string $queue, int $jobId): void
            {
            }
            public function nack(string $queue, int $jobId, int $delaySeconds = 0): void
            {
            }
        };
        $dispatcher = new JobDispatcher($storage, new QueueManager($plain), new FrozenClock(1700000000));
        try {
            $dispatcher->dispatch('t', [], 'default', 3, null, 1800000000);
            self::fail('Unsupported scheduled dispatch must fail before storage');
        } catch (\Oeltima\SimpleQueue\Exception\QueueException) {
            $this->addToAssertionCount(1);
        }
        self::assertSame(0, $storage->count());
        $manager = new QueueManager($plain);
        try {
            $manager->enqueueDelayed(1, 'default', 1800000000);
            self::fail('Direct delayed must fail');
        } catch (\Oeltima\SimpleQueue\Exception\QueueException) {
            $this->addToAssertionCount(1);
        }
        // Database storage-backed scheduling allowed.
        $dbStorage = new InMemoryJobStorage(new FrozenClock(1700000000));
        $dbDriver = new DatabaseQueueDriver($dbStorage);
        $dbDispatcher = new JobDispatcher($dbStorage, new QueueManager($dbDriver), new FrozenClock(1700000000));
        $id = $dbDispatcher->dispatch('t', [], 'default', 3, null, 1800000000);
        self::assertGreaterThan(0, $id);
    }

    public function testDatabaseDriverValidationAndBatch(): void
    {
        $storage = new InMemoryJobStorage(new FrozenClock());
        try {
            new DatabaseQueueDriver($storage, 0);
            self::fail('Non-positive poll must fail');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        $driver = new DatabaseQueueDriver($storage, 250, new FrozenClock(), new SystemSleeper());
        $driver->enqueueBatch('q', [1, 2, 3]);
        try {
            $driver->enqueueBatch('q', [1, 0]);
            self::fail('Invalid batch must fail');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        try {
            $driver->dequeueClaimedForWorker('q', -1, 'w');
            self::fail('Negative timeout must fail');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        self::assertNull($driver->dequeueClaimedForWorker('empty', 0, 'w'));
    }

    public function testReconcilerInvalidAndBatchPaths(): void
    {
        $clock = new FrozenClock();
        $storage = new InMemoryJobStorage($clock);
        $driver = new InMemoryQueueDriver($clock);
        $storage->createJob('t', [], 'default', 3);
        $result = (new QueueReconciler($storage, $driver, $clock))->reconcile('default', new ReconcileOptions());
        self::assertSame(1, $result->restored);
        self::assertSame(0, $result->invalid);
        try {
            new ReconcileOptions(cursor: 0);
            self::fail('Bad cursor must fail');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        try {
            new ReconcileOptions(pageSize: 10001);
            self::fail('Bad page must fail');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        try {
            new ReconcileOptions(maxDurationSeconds: INF);
            self::fail('Bad duration must fail');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testMiddlewareOneShotAndProgressLoss(): void
    {
        $storage = new InMemoryJobStorage(new FrozenClock());
        $driver = new InMemoryQueueDriver(new FrozenClock());
        $registry = new JobRegistry();
        $handler = new class implements JobHandlerInterface {
            public function handle(int $jobId, array $payload, ?callable $progress = null): mixed
            {
                return 'ok';
            }
        };
        $registry->register('t', get_class($handler));
        $registry->middleware->register(new class implements \Oeltima\SimpleQueue\Contract\JobMiddlewareInterface {
            public function process(\Oeltima\SimpleQueue\Contract\JobContextInterface $context): mixed
            {
                $first = $context->proceed();
                try {
                    $context->proceed();
                    \PHPUnit\Framework\Assert::fail('Second proceed must fail');
                } catch (\LogicException) {
                    // Expected one-shot.
                }
                return $first;
            }
        });
        $worker = new Worker($storage, new QueueManager($driver), $registry, null, 'default', []);
        $dispatcher = new JobDispatcher($storage, new QueueManager($driver), new FrozenClock());
        $dispatcher->dispatch('t', []);
        self::assertTrue($worker->processOne());
    }
}
