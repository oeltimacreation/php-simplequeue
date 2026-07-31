<?php

declare(strict_types=1);

use Oeltima\SimpleQueue\Driver\RedisQueueDriver;
use Oeltima\SimpleQueue\JobDispatcher;
use Oeltima\SimpleQueue\QueueManager;
use Oeltima\SimpleQueue\Storage\InMemoryJobStorage;
use Predis\Client;

/** Number of due delayed jobs seeded for the large-backlog promotion scenario. */
const PROMOTION_BACKLOG_JOBS = 10_000;

/**
 * Set up a Redis fixture wired to a dispatcher backed by in-memory storage.
 *
 * @return array{inner: Client, client: BenchmarkRedisClient, driver: RedisQueueDriver, prefix: string, dispatcher: JobDispatcher}
 */
function redisDispatchFixture(BenchmarkOptions $options, BenchmarkScenario $scenario): array
{
    $fixture = redisSetup($options, $scenario);
    $fixture['dispatcher'] = new JobDispatcher(new InMemoryJobStorage(), new QueueManager($fixture['driver']));
    return $fixture;
}

/** @return array<string, mixed> */
function redisBatchBenchmark(BenchmarkOptions $options): array
{
    $scenario = BenchmarkScenario::named(['value' => 'redis.dispatch_batch']);
    return benchmark($scenario, $options, static function () use ($options, $scenario): Closure {
        $fixture = redisSetup($options, $scenario);
        $fixture['client']->resetCounts();
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
    $scenario = BenchmarkScenario::named(['value' => 'redis.dispatch_scheduled_' . $mode]);
    return benchmark($scenario, $options, static function () use ($options, $scenario, $mode): Closure {
        $fixture = redisDispatchFixture($options, $scenario);
        $jobs = min($options->jobs, 100);
        $availableAt = time() + 3600;
        $fixture['client']->resetCounts();
        return static function () use ($fixture, $jobs, $availableAt, $mode, $options): array {
            if ($mode === 'batch') {
                $jobIds = $fixture['dispatcher']->dispatchBatch(
                    'benchmark.noop',
                    array_slice(payloads($options), 0, $jobs),
                    availableAt: $availableAt
                );
                return redisMetrics($fixture, ['operations' => count($jobIds)]);
            }
            $dispatched = 0;
            for ($index = 0; $index < $jobs; $index++) {
                $fixture['dispatcher']->dispatch('benchmark.noop', ['index' => $index], availableAt: $availableAt);
                $dispatched++;
            }
            return redisMetrics($fixture, ['operations' => $dispatched]);
        };
    });
}

/** @return array<string, mixed> */
function redisPromoteDelayedBenchmark(BenchmarkOptions $options): array
{
    $scenario = BenchmarkScenario::named(['value' => 'redis.promote_delayed']);
    return benchmark($scenario, $options, static function () use ($options, $scenario): Closure {
        $fixture = redisSetup($options, $scenario);
        $backlog = PROMOTION_BACKLOG_JOBS;
        $members = [];
        $due = time() - 1;
        for ($id = 1; $id <= $backlog; $id++) {
            $members[$id] = $due;
        }
        $fixture['inner']->zadd($fixture['prefix'] . ':queue:default:delayed', $members);
        $fixture['client']->resetCounts();
        return static function () use ($fixture, $backlog): array {
            $promoted = $fixture['driver']->promoteDelayedJobs('default', $backlog);
            return redisMetrics($fixture, ['operations' => $promoted]);
        };
    });
}

/** @return array<string, mixed> */
function redisAckBenchmark(BenchmarkOptions $options): array
{
    $scenario = BenchmarkScenario::named(['value' => 'redis.dequeue_ack']);
    return benchmark($scenario, $options, static function () use ($options, $scenario): Closure {
        $fixture = redisSetup($options, $scenario);
        $fixture['driver']->enqueueBatch('default', range(1, min($options->jobs, 100)));
        $fixture['client']->resetCounts();
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
    $scenario = BenchmarkScenario::named(['value' => 'redis.retry']);
    return benchmark($scenario, $options, static function () use ($options, $scenario): Closure {
        $fixture = redisSetup($options, $scenario);
        $jobs = min($options->jobs, 100);
        $fixture['driver']->enqueueBatch('default', range(1, $jobs));
        $fixture['client']->resetCounts();
        return static function () use ($fixture, $jobs): array {
            $processed = 0;
            for ($index = 0; $index < $jobs; $index++) {
                $jobId = $fixture['driver']->dequeue('default', 0);
                if ($jobId !== null) {
                    $fixture['driver']->nack('default', $jobId, 60);
                    $processed++;
                }
            }
            return redisMetrics($fixture, ['operations' => $processed]);
        };
    });
}

/** @return array<string, mixed> */
function redisRepairBenchmark(BenchmarkOptions $options): array
{
    $scenario = BenchmarkScenario::named(['value' => 'redis.repair_unscored']);
    return benchmark($scenario, $options, static function () use ($options, $scenario): Closure {
        $fixture = redisSetup($options, $scenario);
        $jobs = min($options->jobs, 100);
        $processing = $fixture['prefix'] . ':queue:default:processing';
        $fixture['inner']->lpush($processing, array_map('strval', range(1, $jobs)));
        $fixture['client']->resetCounts();
        return static function () use ($fixture, $jobs): array {
            $fixture['driver']->recoverStaleProcessing('default', 600, $jobs);
            return redisMetrics($fixture, ['operations' => $jobs]);
        };
    });
}
