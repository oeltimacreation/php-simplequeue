<?php

declare(strict_types=1);

use Oeltima\SimpleQueue\JobDispatcher;
use Oeltima\SimpleQueue\QueueManager;
use Oeltima\SimpleQueue\Storage\InMemoryJobStorage;

// Number of due delayed jobs seeded for the large-backlog promotion scenario.
const PROMOTION_BACKLOG_JOBS = 10_000;

/**
 * Run a Redis benchmark scenario through the shared fixture lifecycle.
 *
 * @param callable(array{
 *     inner: \Predis\Client,
 *     client: BenchmarkRedisClient,
 *     driver: \Oeltima\SimpleQueue\Driver\RedisQueueDriver,
 *     prefix: string
 * }): Closure $setup
 * @return array<string, mixed>
 */
function redisBenchmark(BenchmarkOptions $options, string $name, callable $setup): array
{
    $scenario = BenchmarkScenario::named(['value' => $name]);
    return benchmark($scenario, $options, static function () use ($options, $scenario, $setup): Closure {
        $fixture = redisSetup($options, $scenario);
        $operation = $setup($fixture);
        $fixture['client']->resetCounts();
        return $operation;
    });
}

/** @return array<string, mixed> */
function redisBatchBenchmark(BenchmarkOptions $options): array
{
    return redisBenchmark($options, 'redis.dispatch_batch', static function (array $fixture) use ($options): Closure {
        return static function () use ($fixture, $options): array {
            $jobs = min($options->jobs, 100);
            $fixture['driver']->enqueueBatch('default', range(1, $jobs));
            return redisMetrics($fixture, ['operations' => $jobs]);
        };
    });
}

/** @return array<string, mixed> */
function redisScheduledBenchmark(BenchmarkOptions $options, string $mode): array
{
    return redisBenchmark(
        $options,
        'redis.dispatch_scheduled_' . $mode,
        static function (array $fixture) use ($options, $mode): Closure {
            $dispatcher = new JobDispatcher(new InMemoryJobStorage(), new QueueManager($fixture['driver']));
            $jobs = min($options->jobs, 100);
            $availableAt = time() + 3600;
            return static function () use ($fixture, $dispatcher, $jobs, $availableAt, $mode, $options): array {
                if ($mode === 'batch') {
                    $jobIds = $dispatcher->dispatchBatch(
                        'benchmark.noop',
                        array_slice(payloads($options), 0, $jobs),
                        availableAt: $availableAt
                    );
                    return redisMetrics($fixture, ['operations' => count($jobIds)]);
                }
                $dispatched = 0;
                for ($index = 0; $index < $jobs; $index++) {
                    $dispatcher->dispatch('benchmark.noop', ['index' => $index], availableAt: $availableAt);
                    $dispatched++;
                }
                return redisMetrics($fixture, ['operations' => $dispatched]);
            };
        }
    );
}

/** @return array<string, mixed> */
function redisPromoteDelayedBenchmark(BenchmarkOptions $options): array
{
    return redisBenchmark($options, 'redis.promote_delayed', static function (array $fixture): Closure {
        $backlog = PROMOTION_BACKLOG_JOBS;
        $members = [];
        $due = time() - 1;
        for ($id = 1; $id <= $backlog; $id++) {
            $members[$id] = $due;
        }
        $fixture['inner']->zadd($fixture['prefix'] . ':queue:default:delayed', $members);
        return static function () use ($fixture, $backlog): array {
            $promoted = $fixture['driver']->promoteDelayedJobs('default', $backlog);
            return redisMetrics($fixture, ['operations' => $promoted]);
        };
    });
}

/** @return array<string, mixed> */
function redisAckBenchmark(BenchmarkOptions $options): array
{
    return redisBenchmark($options, 'redis.dequeue_ack', static function (array $fixture) use ($options): Closure {
        $fixture['driver']->enqueueBatch('default', range(1, min($options->jobs, 100)));
        return static function () use ($fixture): array {
            $processed = 0;
            while (($jobId = $fixture['driver']->dequeue('default', 0)) !== null) {
                $fixture['driver']->ack('default', $jobId);
                $processed++;
            }
            return redisMetrics($fixture, ['operations' => $processed]);
        };
    });
}

/** @return array<string, mixed> */
function redisRetryBenchmark(BenchmarkOptions $options): array
{
    return redisBenchmark($options, 'redis.retry', static function (array $fixture) use ($options): Closure {
        $jobs = min($options->jobs, 100);
        $fixture['driver']->enqueueBatch('default', range(1, $jobs));
        return static function () use ($fixture, $jobs): array {
            $processed = runRedisRetries($fixture, $jobs);
            return redisMetrics($fixture, ['operations' => $processed]);
        };
    });
}

/**
 * @param array{
 *     inner: \Predis\Client,
 *     client: BenchmarkRedisClient,
 *     driver: \Oeltima\SimpleQueue\Driver\RedisQueueDriver,
 *     prefix: string
 * } $fixture
 */
function runRedisRetries(array $fixture, int $jobs): int
{
    $processed = 0;
    for ($index = 0; $index < $jobs; $index++) {
        $jobId = $fixture['driver']->dequeue('default', 0);
        if ($jobId === null) {
            continue;
        }
        $fixture['driver']->nack('default', $jobId, 60);
        $processed++;
    }

    return $processed;
}

/** @return array<string, mixed> */
function redisRepairBenchmark(BenchmarkOptions $options): array
{
    return redisBenchmark($options, 'redis.repair_unscored', static function (array $fixture) use ($options): Closure {
        $jobs = min($options->jobs, 100);
        $processing = $fixture['prefix'] . ':queue:default:processing';
        $fixture['inner']->lpush($processing, array_map('strval', range(1, $jobs)));
        return static function () use ($fixture, $jobs): array {
            $fixture['driver']->recoverStaleProcessing('default', 600, $jobs);
            return redisMetrics($fixture, ['operations' => $jobs]);
        };
    });
}
