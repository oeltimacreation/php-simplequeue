<?php

declare(strict_types=1);

use Oeltima\SimpleQueue\Driver\InMemoryQueueDriver;
use Oeltima\SimpleQueue\Driver\DatabaseQueueDriver;
use Oeltima\SimpleQueue\JobDispatcher;
use Oeltima\SimpleQueue\JobRegistry;
use Oeltima\SimpleQueue\QueueManager;
use Oeltima\SimpleQueue\Storage\InMemoryJobStorage;
use Oeltima\SimpleQueue\Worker;

/** @return array<string, mixed> */
function sqliteClaimBenchmark(BenchmarkOptions $options): array
{
    $scenario = BenchmarkScenario::named(['value' => 'sqlite.claim']);
    return benchmark($scenario, $options, static function () use ($options): Closure {
        [$pdo, $storage] = sqliteStorage();
        (new JobDispatcher($storage, QueueManager::database($storage)))->dispatchBatch(
            'benchmark.noop',
            payloads($options)
        );
        $pdo->resetCounts();
        return static function () use ($storage, $pdo, $options): array {
            $claimed = 0;
            for ($index = 0; $index < $options->jobs; $index++) {
                $claimed += $storage->claimNextAvailable('default', 'benchmark-worker') === null ? 0 : 1;
            }
            return databaseCounts($pdo, ['operations' => $claimed]);
        };
    });
}

/** @return array<string, mixed> */
function sqliteClaimedDequeueBenchmark(BenchmarkOptions $options): array
{
    $scenario = BenchmarkScenario::named(['value' => 'sqlite.claimed_dequeue']);
    return benchmark($scenario, $options, static function () use ($options): Closure {
        [$pdo, $storage] = sqliteStorage();
        $storage->createJobs(array_map(
            static fn (array $payload): array => ['type' => 'benchmark.noop', 'payload' => $payload],
            payloads($options)
        ));
        $driver = new DatabaseQueueDriver($storage);
        $pdo->resetCounts();
        return static function () use ($driver, $pdo, $options): array {
            $claimed = 0;
            for ($index = 0; $index < $options->jobs; $index++) {
                $claim = $driver->dequeueClaimedForWorker('default', 0, 'benchmark-worker');
                $claimed += $claim === null ? 0 : 1;
            }
            return databaseCounts($pdo, ['operations' => $claimed]);
        };
    });
}

/** @return array<string, mixed> */
function workerExecutionBenchmark(BenchmarkOptions $options): array
{
    return workerBenchmark($options, BenchmarkScenario::named(['value' => 'worker.execute_ack']));
}

/** @return array<string, mixed> */
function workerResultSerializationBenchmark(BenchmarkOptions $options): array
{
    return workerBenchmark($options, BenchmarkScenario::named(['value' => 'worker.result_serialization']));
}

/** @return array<string, mixed> */
function workerRetryBenchmark(BenchmarkOptions $options): array
{
    return workerBenchmark($options, BenchmarkScenario::named(['value' => 'worker.retry']));
}

/** @return array<string, mixed> */
function workerBenchmark(BenchmarkOptions $options, BenchmarkScenario $scenario): array
{
    $retry = $scenario->sameAs(BenchmarkScenario::named(['value' => 'worker.retry']));
    $handler = benchmarkHandler($scenario);
    $jobType = $retry ? 'benchmark.fail' : 'benchmark.noop';
    $workerOptions = $retry ? ['retry_base_delay' => 60, 'retry_max_delay' => 60] : [];
    return benchmark(
        $scenario,
        $options,
        static function () use ($options, $handler, $jobType, $workerOptions): Closure {
            [$pdo, $storage] = sqliteStorage();
            $driver = new BenchmarkQueueDriver(new InMemoryQueueDriver());
            $queueManager = new QueueManager($driver);
            (new JobDispatcher($storage, $queueManager))->dispatchBatch(
                $jobType,
                payloads($options),
                maxAttempts: 2
            );
            $registry = new JobRegistry();
            $registry->register($jobType, $handler);
            $worker = new Worker($storage, $queueManager, $registry, options: array_merge([
            'lock_file' => null,
            'poll_timeout' => 0,
            ], $workerOptions));
            $pdo->resetCounts();
            $driver->resetCounts();
            return static function () use ($worker, $pdo, $options, $driver): array {
                $processed = 0;
                for ($index = 0; $index < $options->jobs; $index++) {
                    $processed += $worker->processOne() ? 1 : 0;
                }
                return array_merge(databaseCounts($pdo, ['operations' => $processed]), [
                'driver_roundtrips' => $driver->roundTrips(),
                ]);
            };
        }
    );
}

/** @return array<string, mixed> */
function listenerWorkerBenchmark(BenchmarkOptions $options): array
{
    $scenario = BenchmarkScenario::named(['value' => 'worker.event_listener_execute_ack']);
    return benchmark($scenario, $options, static function () use ($options): Closure {
        [$pdo, $storage] = sqliteStorage();
        $driver = new BenchmarkQueueDriver(new InMemoryQueueDriver());
        $queueManager = new QueueManager($driver);
        $dispatcher = new JobDispatcher($storage, $queueManager);
        $dispatcher->dispatchBatch('benchmark.noop', payloads($options));
        $driver->resetCounts();
        $pdo->resetCounts();

        $registry = new JobRegistry();
        $registry->register('benchmark.noop', NoopBenchmarkHandler::class);
        $eventDeliveries = 0;
        $worker = new Worker($storage, $queueManager, $registry, options: [
            'lock_file' => null,
            'poll_timeout' => 0,
            'event_listener' => static function (string $event, array $data) use (&$eventDeliveries): void {
                $eventDeliveries++;
            },
        ]);

        return static function () use ($worker, $pdo, $options, $driver, &$eventDeliveries): array {
            $processed = 0;
            for ($index = 0; $index < $options->jobs; $index++) {
                $processed += $worker->processOne() ? 1 : 0;
            }

            return array_merge(databaseCounts($pdo, ['operations' => $processed]), [
                'driver_roundtrips' => $driver->roundTrips(),
                'event_deliveries' => $eventDeliveries,
            ]);
        };
    });
}

/** @return array<string, mixed> */
function middlewareWorkerBenchmark(BenchmarkOptions $options): array
{
    $scenario = BenchmarkScenario::named(['value' => 'worker.middleware_execute_ack']);
    return benchmark($scenario, $options, static function () use ($options): Closure {
        $storage = new InMemoryJobStorage();
        $driver = new BenchmarkQueueDriver(new InMemoryQueueDriver());
        $queueManager = new QueueManager($driver);
        $dispatcher = new JobDispatcher($storage, $queueManager);
        $dispatcher->dispatchBatch('benchmark.noop', payloads($options));
        $driver->resetCounts();

        $registry = new JobRegistry();
        $registry->register('benchmark.noop', NoopBenchmarkHandler::class);
        $registry->middleware->register(new NoopBenchmarkMiddleware());
        $worker = new Worker($storage, $queueManager, $registry, options: [
            'lock_file' => null,
            'poll_timeout' => 0,
        ]);

        return static function () use ($worker, $options, $driver): array {
            $processed = 0;
            for ($index = 0; $index < $options->jobs; $index++) {
                $processed += $worker->processOne() ? 1 : 0;
            }

            return [
                'operations' => $processed,
                'driver_roundtrips' => $driver->roundTrips(),
            ];
        };
    });
}

/** @return array<string, mixed> */
function idleCpuMemoryBenchmark(BenchmarkOptions $options): array
{
    $scenario = BenchmarkScenario::named(['value' => 'worker.idle_cpu_memory']);
    return benchmark($scenario, $options, static function () use ($options): Closure {
        [$pdo, $storage] = sqliteStorage();
        $worker = new Worker(
            $storage,
            new QueueManager(new InMemoryQueueDriver()),
            new JobRegistry(),
            options: [
                'lock_file' => null,
                'poll_timeout' => 0,
                'promote_interval' => 0,
                'recovery_interval' => 0,
            ]
        );
        $pdo->resetCounts();
        return static function () use ($worker, $pdo, $options): array {
            $cycles = 0;
            for ($index = 0; $index < $options->idleCycles; $index++) {
                $worker->processOne();
                $cycles++;
            }
            return databaseCounts($pdo, ['operations' => $cycles]);
        };
    });
}
