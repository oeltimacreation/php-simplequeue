<?php

declare(strict_types=1);

use Oeltima\SimpleQueue\Driver\InMemoryQueueDriver;
use Oeltima\SimpleQueue\AdminManager;
use Oeltima\SimpleQueue\JobRegistry;
use Oeltima\SimpleQueue\QueueManager;
use Oeltima\SimpleQueue\QueueReconciler;
use Oeltima\SimpleQueue\ReconcileOptions;
use Oeltima\SimpleQueue\Worker;
use Oeltima\SimpleQueue\Storage\InMemoryJobStorage;

/** @return array<string, mixed> */
function reconciliationBenchmark(BenchmarkOptions $options, string $distribution, bool $optimized): array
{
    $path = $optimized ? 'optimized' : 'fallback';
    $scenario = BenchmarkScenario::named(['value' => "sqlite.reconcile.{$path}.{$distribution}"]);
    return benchmark($scenario, $options, static function () use ($options, $distribution, $optimized): Closure {
        [$pdo, $storage] = sqliteStorage();
        $jobIds = $storage->createJobs(array_map(
            static fn (array $payload): array => ['type' => 'benchmark.noop', 'payload' => $payload],
            payloads($options)
        ));
        $queue = new InMemoryQueueDriver();
        $present = match ($distribution) {
            'all_hit' => $jobIds,
            'mixed' => array_slice($jobIds, 0, intdiv(count($jobIds), 2)),
            'all_miss' => [],
            default => throw new InvalidArgumentException('Unknown reconciliation distribution'),
        };
        $queue->enqueueBatch('default', $present);
        $instrumented = new BenchmarkQueueDriver($queue);
        $driver = $optimized ? $instrumented : new BenchmarkFallbackQueueDriver($instrumented);
        $reconciler = new QueueReconciler($storage, $driver);
        $pdo->resetCounts();
        $instrumented->resetCounts();
        return static function () use ($reconciler, $pdo, $options, $instrumented): array {
            $result = $reconciler->reconcile('default', new ReconcileOptions(
                pageSize: $options->jobs,
                membershipScanLimit: $options->jobs,
                maxDurationSeconds: 60.0
            ));
            return array_merge(databaseCounts($pdo, ['operations' => $result->scanned]), [
                'driver_roundtrips' => $instrumented->roundTrips(),
            ]);
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
