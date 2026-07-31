<?php

declare(strict_types=1);

use Oeltima\SimpleQueue\Driver\RedisQueueDriver;
use Predis\Client;

/** @return list<array<string, mixed>> */
function redisBenchmarks(BenchmarkOptions $options): array
{
    return [
        redisBatchBenchmark($options),
        redisScheduledSingleBenchmark($options),
        redisScheduledBatchBenchmark($options),
        redisPromoteDelayedBenchmark($options),
        redisAckBenchmark($options),
        redisRetryBenchmark($options),
        redisRepairBenchmark($options),
    ];
}

/** @return array{inner: Client, client: BenchmarkRedisClient, driver: RedisQueueDriver, prefix: string} */
function redisSetup(BenchmarkOptions $options, BenchmarkScenario $scenario): array
{
    $inner = new Client(['scheme' => 'tcp', 'host' => $options->redisHost, 'port' => $options->redisPort]);
    $inner->connect();
    $client = new BenchmarkRedisClient($inner);
    $prefix = sprintf('sq-benchmark:%s:%s', $scenario->key(), bin2hex(random_bytes(6)));
    return ['inner' => $inner, 'client' => $client, 'driver' => new RedisQueueDriver($client, $prefix), 'prefix' => $prefix];
}

/** @param array{inner: Client, client: BenchmarkRedisClient, driver: RedisQueueDriver, prefix: string} $fixture */
function redisCleanup(array $fixture): Closure
{
    return static function () use ($fixture): void {
        $keys = $fixture['inner']->keys($fixture['prefix'] . ':*');
        if ($keys !== []) {
            $fixture['inner']->del($keys);
        }
    };
}

/**
 * @param array{inner: Client, client: BenchmarkRedisClient, driver: RedisQueueDriver, prefix: string} $fixture
 * @param array{operations: int} $operation
 * @return array<string, int|Closure>
 */
function redisMetrics(array $fixture, array $operation): array
{
    return [
        'operations' => $operation['operations'],
        'redis_commands' => $fixture['client']->commands,
        'redis_roundtrips' => $fixture['client']->roundTrips,
        'redis_wire_bytes' => $fixture['client']->wireBytes,
        'cleanup' => redisCleanup($fixture),
    ];
}
