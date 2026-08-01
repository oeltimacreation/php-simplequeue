<?php

declare(strict_types=1);

use Oeltima\SimpleQueue\Driver\InMemoryQueueDriver;
use Oeltima\SimpleQueue\JobDispatcher;
use Oeltima\SimpleQueue\JobRegistry;
use Oeltima\SimpleQueue\QueueManager;
use Oeltima\SimpleQueue\Worker;

/** @return array<string, mixed> */
function sqliteClaimBenchmark(BenchmarkOptions $options): array
{
    $scenario = BenchmarkScenario::named(['value' => 'sqlite.claim']);
    return benchmark($scenario, $options, static function () use ($options): Closure {
        [$pdo, $storage] = sqliteStorage();
        (new JobDispatcher($storage, QueueManager::database($storage)))->dispatchBatch('benchmark.noop', payloads($options));
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
function workerExecutionBenchmark(BenchmarkOptions $options): array
{
    return workerBenchmark($options, BenchmarkScenario::named(['value' => 'worker.execute_ack']));
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
    return benchmark($scenario, $options, static function () use ($options, $handler, $jobType, $workerOptions): Closure {
        [$pdo, $storage] = sqliteStorage();
        $driver = new InMemoryQueueDriver();
        (new JobDispatcher($storage, new QueueManager($driver)))->dispatchBatch(
            $jobType,
            payloads($options),
            maxAttempts: 2
        );
        $registry = new JobRegistry();
        $registry->register($jobType, $handler);
        $worker = new Worker($storage, new QueueManager($driver), $registry, options: array_merge([
            'lock_file' => null,
            'poll_timeout' => 0,
        ], $workerOptions));
        $pdo->resetCounts();
        return static function () use ($worker, $pdo, $options): array {
            $processed = 0;
            for ($index = 0; $index < $options->jobs; $index++) {
                $processed += $worker->processOne() ? 1 : 0;
            }
            return databaseCounts($pdo, ['operations' => $processed]);
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
            $start = getrusage();
            $cycles = 0;
            for ($index = 0; $index < $options->idleCycles; $index++) {
                $worker->processOne();
                $cycles++;
            }
            $metrics = databaseCounts($pdo, ['operations' => $cycles]);
            $metrics['cpu_seconds'] = cpuSeconds($start, getrusage());
            return $metrics;
        };
    });
}
