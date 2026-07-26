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
        memoryBatchBenchmark($options),
        sqliteSingleBenchmark($options),
        sqliteBatchBenchmark($options),
        sqliteClaimBenchmark($options),
        workerExecutionBenchmark($options),
        workerRetryBenchmark($options),
        sqliteReconcileBenchmark($options),
        idleMaintenanceBenchmark($options),
    ];
}

/** @return array<string, mixed> */
function memoryBatchBenchmark(BenchmarkOptions $options): array
{
    $payloads = payloads($options);
    $scenario = BenchmarkScenario::named(['value' => 'memory.dispatch_batch']);
    return benchmark($scenario, $options, static function () use ($payloads): Closure {
        $dispatcher = new JobDispatcher(new InMemoryJobStorage(), new QueueManager(new InMemoryQueueDriver()));
        return static function () use ($dispatcher, $payloads): array {
            return ['operations' => count($dispatcher->dispatchBatch('benchmark.noop', $payloads))];
        };
    });
}

/** @return array<string, mixed> */
function sqliteSingleBenchmark(BenchmarkOptions $options): array
{
    $scenario = BenchmarkScenario::named(['value' => 'sqlite.dispatch_single']);
    return benchmark($scenario, $options, static function () use ($options): Closure {
        [$pdo, $storage] = sqliteStorage();
        $dispatcher = new JobDispatcher($storage, QueueManager::database($storage));
        return static function () use ($dispatcher, $pdo, $options): array {
            for ($index = 0; $index < $options->jobs; $index++) {
                $dispatcher->dispatch('benchmark.noop', ['index' => $index]);
            }
            return databaseCounts($pdo, ['operations' => $options->jobs]);
        };
    });
}

/** @return array<string, mixed> */
function sqliteBatchBenchmark(BenchmarkOptions $options): array
{
    $payloads = payloads($options);
    $scenario = BenchmarkScenario::named(['value' => 'sqlite.dispatch_batch']);
    return benchmark($scenario, $options, static function () use ($payloads, $options): Closure {
        [$pdo, $storage] = sqliteStorage();
        $dispatcher = new JobDispatcher($storage, QueueManager::database($storage));
        return static function () use ($dispatcher, $pdo, $payloads, $options): array {
            $dispatcher->dispatchBatch('benchmark.noop', $payloads);
            return databaseCounts($pdo, ['operations' => $options->jobs]);
        };
    });
}
