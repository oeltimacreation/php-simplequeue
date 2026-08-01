<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/benchmark-types.php';
require_once __DIR__ . '/redis-instrumentation.php';
require_once __DIR__ . '/benchmark-handlers.php';
require_once __DIR__ . '/benchmark-storage.php';
require_once __DIR__ . '/benchmark-runner.php';
require_once __DIR__ . '/local-dispatch.php';
require_once __DIR__ . '/local-worker.php';
require_once __DIR__ . '/local-maintenance.php';
require_once __DIR__ . '/redis-support.php';
require_once __DIR__ . '/redis-scenarios.php';
require_once __DIR__ . '/operation-count-checks.php';

function runBenchmarks(): void
{
    $options = BenchmarkOptions::fromCli();
    $results = localBenchmarks($options);
    if ($options->redisHost !== null) {
        $results = array_merge($results, redisBenchmarks($options));
    }
    assertHotLoopCounters($results);

    echo json_encode([
        'environment' => [
            'php' => PHP_VERSION,
            'platform' => php_uname('s') . ' ' . php_uname('m'),
            'pdo_drivers' => \PDO::getAvailableDrivers(),
            'redis' => $options->redisHost === null ? null : "{$options->redisHost}:{$options->redisPort}",
        ],
        'configuration' => [
            'jobs' => $options->jobs,
            'iterations' => $options->iterations,
            'warmup' => $options->warmup,
            'idle_cycles' => $options->idleCycles,
        ],
        'results' => $results,
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
}

runBenchmarks();
