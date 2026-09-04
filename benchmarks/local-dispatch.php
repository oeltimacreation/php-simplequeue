<?php

declare(strict_types=1);

use Oeltima\SimpleQueue\Driver\InMemoryQueueDriver;
use Oeltima\SimpleQueue\JobDispatcher;
use Oeltima\SimpleQueue\QueueManager;
use Oeltima\SimpleQueue\Storage\InMemoryJobStorage;

/** @return list<array<string, mixed>> */
function localBenchmarks(BenchmarkOptions $options): array
{
    return [
        memoryQueueBenchmark($options),
        memoryQueueBenchmark($options, true),
        memoryBatchBenchmark($options),
        memoryBatchBenchmark($options, true),
        sqliteSingleBenchmark($options),
        sqliteSingleBenchmark($options, true),
        sqliteBatchBenchmark($options),
        sqliteBatchBenchmark($options, true),
        sqliteBatchBoundaryBenchmark($options),
        sqliteClaimBenchmark($options),
        sqliteClaimedDequeueBenchmark($options),
        workerExecutionBenchmark($options),
        workerResultSerializationBenchmark($options),
        listenerWorkerBenchmark($options),
        middlewareWorkerBenchmark($options),
        workerRetryBenchmark($options),
        failedJobRequeueBenchmark($options),
        reconciliationBenchmark($options, 'all_miss', true),
        reconciliationBenchmark($options, 'all_hit', true),
        reconciliationBenchmark($options, 'mixed', true),
        reconciliationBenchmark($options, 'all_miss', false),
        reconciliationBenchmark($options, 'all_hit', false),
        reconciliationBenchmark($options, 'mixed', false),
        idleMaintenanceBenchmark($options),
        idleCpuMemoryBenchmark($options),
    ];
}

/** @return array<string, mixed> */
function memoryQueueBenchmark(BenchmarkOptions $options, bool $batch = false): array
{
    $name = $batch ? 'memory.queue_batch' : 'memory.queue_repeated_single';
    return benchmark(
        BenchmarkScenario::named(['value' => $name]),
        $options,
        static function () use ($options, $batch): Closure {
            $driver = new InMemoryQueueDriver();
            $jobIds = range(1, $options->jobs);
            return static function () use ($driver, $jobIds, $batch): array {
                enqueueMemoryJobs($driver, $jobIds, $batch);
                drainMemoryJobs($driver, $jobIds);
                return ['operations' => count($jobIds)];
            };
        }
    );
}

/** @param list<int> $jobIds */
function enqueueMemoryJobs(InMemoryQueueDriver $driver, array $jobIds, bool $batch): void
{
    if ($batch) {
        $driver->enqueueBatch('default', $jobIds);
        return;
    }
    foreach ($jobIds as $jobId) {
        $driver->enqueue('default', $jobId);
    }
}

/** @param list<int> $jobIds */
function drainMemoryJobs(InMemoryQueueDriver $driver, array $jobIds): void
{
    foreach ($jobIds as $expected) {
        if ($driver->dequeue('default', 0) !== $expected) {
            throw new RuntimeException('In-memory queue benchmark lost FIFO order');
        }
        $driver->ack('default', $expected);
    }
}

/** @return array<string, mixed> */
function memoryBatchBenchmark(BenchmarkOptions $options, bool $scheduled = false): array
{
    $payloads = payloads($options);
    $scenario = BenchmarkScenario::named([
        'value' => $scheduled ? 'memory.dispatch_scheduled_batch' : 'memory.dispatch_batch',
    ]);
    return benchmark($scenario, $options, static function () use ($payloads, $scheduled): Closure {
        $dispatcher = new JobDispatcher(new InMemoryJobStorage(), new QueueManager(new InMemoryQueueDriver()));
        $availableAt = $scheduled ? time() + 3600 : null;
        return static function () use ($dispatcher, $payloads, $availableAt): array {
            $jobIds = $dispatcher->dispatchBatch('benchmark.noop', $payloads, availableAt: $availableAt);
            return ['operations' => count($jobIds)];
        };
    });
}

/** @return array<string, mixed> */
function sqliteSingleBenchmark(BenchmarkOptions $options, bool $scheduled = false): array
{
    $scenario = BenchmarkScenario::named([
        'value' => $scheduled ? 'sqlite.dispatch_scheduled_single' : 'sqlite.dispatch_single',
    ]);
    return benchmark($scenario, $options, static function () use ($options, $scheduled): Closure {
        [$pdo, $storage] = sqliteStorage();
        $dispatcher = new JobDispatcher($storage, QueueManager::database($storage));
        $availableAt = $scheduled ? time() + 3600 : null;
        return static function () use ($dispatcher, $pdo, $options, $availableAt): array {
            for ($index = 0; $index < $options->jobs; $index++) {
                $dispatcher->dispatch('benchmark.noop', ['index' => $index], availableAt: $availableAt);
            }
            return databaseCounts($pdo, ['operations' => $options->jobs]);
        };
    });
}

/** @return array<string, mixed> */
function sqliteBatchBenchmark(BenchmarkOptions $options, bool $scheduled = false): array
{
    $payloads = payloads($options);
    $scenario = BenchmarkScenario::named([
        'value' => $scheduled ? 'sqlite.dispatch_scheduled_batch' : 'sqlite.dispatch_batch',
    ]);
    return benchmark($scenario, $options, static function () use ($payloads, $options, $scheduled): Closure {
        [$pdo, $storage] = sqliteStorage();
        $dispatcher = new JobDispatcher($storage, QueueManager::database($storage));
        $availableAt = $scheduled ? time() + 3600 : null;
        return static function () use ($dispatcher, $pdo, $payloads, $options, $availableAt): array {
            $dispatcher->dispatchBatch('benchmark.noop', $payloads, availableAt: $availableAt);
            return databaseCounts($pdo, ['operations' => $options->jobs]);
        };
    });
}

/** @return array<string, mixed> */
function sqliteBatchBoundaryBenchmark(BenchmarkOptions $options): array
{
    $scenario = BenchmarkScenario::named(['value' => 'sqlite.dispatch_batch_chunk_boundary']);
    return benchmark($scenario, $options, static function (): Closure {
        [$pdo, $storage] = sqliteStorage();
        $definitions = array_map(
            static fn (int $index): array => ['type' => 'benchmark.noop', 'payload' => ['index' => $index]],
            range(1, 201)
        );
        $pdo->resetCounts();
        return static function () use ($storage, $pdo, $definitions): array {
            $ids = $storage->createJobs($definitions);
            return databaseCounts($pdo, ['operations' => count($ids)]);
        };
    });
}
