<?php

declare(strict_types=1);

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
