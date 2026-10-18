<?php

declare(strict_types=1);

use Oeltima\SimpleQueue\QueueStatsSampler;

/** @return array<string, mixed> */
function idempotentBatchBenchmark(BenchmarkOptions $options): array
{
    $scenario = BenchmarkScenario::named(['value' => 'sqlite.idempotent_batch']);
    return benchmark($scenario, $options, static function () use ($options): Closure {
        [$pdo, $storage] = sqliteStorage();
        $jobs = [];
        foreach (payloads($options) as $payload) {
            $jobs[] = [
                'type' => 'benchmark.noop', 'payload' => $payload, 'requestId' => 'request:' . $payload['index'],
            ];
        }
        return static function () use ($pdo, $storage, $jobs): array {
            $storage->createIdempotentJobs($jobs);
            $storage->createIdempotentJobs($jobs);
            return databaseCounts($pdo, ['operations' => count($jobs)]);
        };
    });
}

/** @return array<string, mixed> */
function payloadReadBenchmark(BenchmarkOptions $options, string $operation, bool $large): array
{
    $size = $large ? 'large' : 'small';
    $scenario = BenchmarkScenario::named(['value' => 'sqlite.' . $operation . '_payload_' . $size]);
    return benchmark($scenario, $options, static function () use ($options, $operation, $large): Closure {
        [$pdo, $storage] = sqliteStorage();
        $jobs = min($options->jobs, 10);
        $payload = ['value' => str_repeat('x', $large ? 1048576 : 32)];
        seedPayloadReadJobs($storage, $jobs, $payload, $operation);
        if ($operation === 'recover') {
            $pdo->exec("UPDATE background_jobs SET locked_at = '2000-01-01 00:00:00'");
        }
        $pdo->resetCounts();
        return static function () use ($pdo, $storage, $jobs, $operation): array {
            runPayloadReadOperation($storage, $jobs, $operation);
            return databaseCounts($pdo, ['operations' => $jobs]);
        };
    });
}

/** @return array<string, mixed> */
function redisReconciliationBenchmark(BenchmarkOptions $options, string $distribution): array
{
    return redisBenchmark($options, 'redis.reconcile.' . $distribution, static function (array $fixture) use (
        $options,
        $distribution
    ): Closure {
        $jobs = min($options->jobs, 10000);
        $hits = match ($distribution) {
            'all_hit' => $jobs,
            'mixed' => intdiv($jobs, 2),
            default => 0,
        };
        // A backlog beyond the inspected prefix exercises bounded misses.
        $fixture['driver']->enqueueBatch('default', range(10001, 20000));
        if ($hits > 0) {
            $fixture['driver']->enqueueBatch('default', range(1, $hits));
        }
        $now = time();
        $times = array_fill_keys(range(1, $jobs), $now);
        return static function () use ($fixture, $times, $now, $jobs): array {
            $fixture['driver']->reconcileNotifications('default', $times, $now, 10000);
            return redisMetrics($fixture, ['operations' => $jobs]);
        };
    });
}

/** @return array<string, mixed> */
function redisSampledStatisticsBenchmark(BenchmarkOptions $options): array
{
    return redisBenchmark($options, 'redis.sampled_statistics', static function (array $fixture) use (
        $options
    ): Closure {
        $sampler = new QueueStatsSampler($fixture['driver'], interval: 3600);
        return static function () use ($fixture, $options, $sampler): array {
            for ($index = 0; $index < $options->jobs; $index++) {
                $sampler->sample();
            }
            return redisMetrics($fixture, ['operations' => $options->jobs]);
        };
    });
}

/** @return array<string, mixed> */
function redisBlockingDequeueBenchmark(BenchmarkOptions $options): array
{
    return redisBenchmark($options, 'redis.dequeue_blocking_ack', static function (array $fixture) use (
        $options
    ): Closure {
        $jobs = min($options->jobs, 100);
        $fixture['driver']->enqueueBatch('default', range(1, $jobs));
        return static function () use ($fixture, $jobs): array {
            for ($index = 0; $index < $jobs; $index++) {
                $id = $fixture['driver']->dequeue('default', 1)
                    ?? throw new RuntimeException('Blocking dequeue lost a seeded job');
                $fixture['driver']->ack('default', $id);
            }
            return redisMetrics($fixture, ['operations' => $jobs]);
        };
    });
}

/** @param array{value: string} $payload */
function seedPayloadReadJobs(
    \Oeltima\SimpleQueue\Storage\PdoJobStorage $storage,
    int $jobs,
    array $payload,
    string $operation
): void {
    for ($index = 0; $index < $jobs; $index++) {
        $id = $storage->createJob('benchmark.noop', $payload);
        if ($operation === 'recover') {
            $storage->claimById($id, 'benchmark');
        }
    }
}

function runPayloadReadOperation(
    \Oeltima\SimpleQueue\Storage\PdoJobStorage $storage,
    int $jobs,
    string $operation
): void {
    if ($operation === 'claim') {
        for ($index = 0; $index < $jobs; $index++) {
            $storage->claimNextAvailable('default', 'benchmark');
        }
    } elseif ($operation === 'recover') {
        $storage->recoverStaleJobsForQueue('default', 600, $jobs);
    } else {
        $storage->listSummaries(limit: $jobs);
        $storage->countByStatus();
        $storage->findSummaries(range(1, $jobs));
    }
}
