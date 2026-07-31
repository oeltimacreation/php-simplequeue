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
        memoryBatchBenchmark($options, true),
        sqliteSingleBenchmark($options),
        sqliteSingleBenchmark($options, true),
        sqliteBatchBenchmark($options),
        sqliteBatchBenchmark($options, true),
        sqliteClaimBenchmark($options),
        workerExecutionBenchmark($options),
        workerRetryBenchmark($options),
        sqliteReconcileBenchmark($options),
        idleMaintenanceBenchmark($options),
        idleCpuMemoryBenchmark($options),
    ];
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
            return ['operations' => count($dispatcher->dispatchBatch('benchmark.noop', $payloads, availableAt: $availableAt))];
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
