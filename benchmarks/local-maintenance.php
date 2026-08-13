<?php

declare(strict_types=1);

use Oeltima\SimpleQueue\Driver\InMemoryQueueDriver;
use Oeltima\SimpleQueue\AdminManager;
use Oeltima\SimpleQueue\JobDispatcher;
use Oeltima\SimpleQueue\JobRegistry;
use Oeltima\SimpleQueue\QueueManager;
use Oeltima\SimpleQueue\QueueReconciler;
use Oeltima\SimpleQueue\ReconcileOptions;
use Oeltima\SimpleQueue\Worker;
use Oeltima\SimpleQueue\Storage\InMemoryJobStorage;

/** @return array<string, mixed> */
function sqliteReconcileBenchmark(BenchmarkOptions $options): array
{
    $scenario = BenchmarkScenario::named(['value' => 'sqlite.reconcile']);
    return benchmark($scenario, $options, static function () use ($options): Closure {
        [$pdo, $storage] = sqliteStorage();
        (new JobDispatcher($storage, QueueManager::database($storage)))->dispatchBatch('benchmark.noop', payloads($options));
        $reconciler = new QueueReconciler($storage, new InMemoryQueueDriver());
        $pdo->resetCounts();
        return static function () use ($reconciler, $pdo, $options): array {
            $result = $reconciler->reconcile('default', new ReconcileOptions(
                pageSize: $options->jobs,
                membershipScanLimit: $options->jobs,
                maxDurationSeconds: 60.0
            ));
            return databaseCounts($pdo, ['operations' => $result->scanned]);
        };
    });
}

/** @return array<string, mixed> */
function idleMaintenanceBenchmark(BenchmarkOptions $options): array
{
    $scenario = BenchmarkScenario::named(['value' => 'worker.idle_maintenance']);
    return benchmark($scenario, $options, static function () use ($options): Closure {
        $clock = new BenchmarkClock($options);
        [$pdo, $storage] = sqliteStorage($clock);
        $worker = new Worker($storage, new QueueManager(new InMemoryQueueDriver($clock)), new JobRegistry(), options: [
            'lock_file' => null,
            'poll_timeout' => 0,
            'max_time' => 1,
            'promote_interval' => 0,
            'recovery_interval' => 0,
            'clock' => $clock,
        ]);
        $pdo->resetCounts();
        return static function () use ($worker, $clock, $pdo): array {
            $worker->run();
            return databaseCounts($pdo, ['operations' => $clock->monotonicReads]);
        };
    });
}

/** @return array<string, mixed> */
function failedJobRequeueBenchmark(BenchmarkOptions $options): array
{
    $scenario = BenchmarkScenario::named(['value' => 'admin.requeue_failed']);
    return benchmark($scenario, $options, static function () use ($options): Closure {
        $storage = new InMemoryJobStorage();
        $driver = new BenchmarkQueueDriver(new InMemoryQueueDriver());
        $jobIds = [];
        for ($index = 0; $index < $options->jobs; $index++) {
            $jobId = $storage->createJob('benchmark.failed', []);
            $claim = $storage->claimById($jobId, 'benchmark-admin');
            if ($claim === null || !$storage->markFailed($claim, 'benchmark failure')) {
                throw new RuntimeException('Unable to create failed benchmark fixture');
            }
            $jobIds[] = $jobId;
        }
        $driver->resetCounts();
        $admin = new AdminManager($storage, new QueueManager($driver));

        return static function () use ($admin, $jobIds, $driver): array {
            $requeued = 0;
            foreach ($jobIds as $jobId) {
                $requeued += $admin->requeueFailed($jobId) ? 1 : 0;
            }

            return [
                'operations' => $requeued,
                'driver_roundtrips' => $driver->roundTrips(),
            ];
        };
    });
}
