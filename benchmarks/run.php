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

/** @return array<string, mixed> */
function benchmarkEnvironment(BenchmarkOptions $options): array
{
    $sqlite = class_exists(\SQLite3::class) ? \SQLite3::version() : [];
    $environment = [
        'php' => PHP_VERSION,
        'platform' => php_uname('s') . ' ' . php_uname('r') . ' ' . php_uname('m'),
        'sapi' => PHP_SAPI,
        'pdo_drivers' => \PDO::getAvailableDrivers(),
        'sqlite' => $sqlite['versionString'] ?? null,
        'redis' => $options->redisHost === null ? null : "{$options->redisHost}:{$options->redisPort}",
        'xdebug_mode' => extension_loaded('xdebug') ? (string) ini_get('xdebug.mode') : null,
        'measurement' => [
            'timer' => 'hrtime(true)',
            'cpu' => function_exists('getrusage') ? 'getrusage() user+system' : null,
            'memory' => 'PHP non-real allocator usage',
            'setup_excluded' => true,
            'cleanup_excluded' => true,
        ],
    ];

    if ($options->redisHost === null) {
        $environment['redis_server'] = null;
        return $environment;
    }

    $client = new \Predis\Client([
        'scheme' => 'tcp',
        'host' => $options->redisHost,
        'port' => $options->redisPort,
    ]);
    try {
        $client->connect();
        $server = $client->info('server');
        $serverInfo = is_array($server) && isset($server['Server']) && is_array($server['Server'])
            ? $server['Server']
            : $server;
        $environment['redis_server'] = [
            'name' => is_array($serverInfo) ? ($serverInfo['server_name'] ?? null) : null,
            'version' => is_array($serverInfo)
                ? ($serverInfo['valkey_version'] ?? $serverInfo['redis_version'] ?? null)
                : null,
        ];
    } finally {
        $client->disconnect();
    }

    return $environment;
}

function runBenchmarks(): void
{
    $options = BenchmarkOptions::fromCli();
    $results = localBenchmarks($options);
    if ($options->redisHost !== null) {
        $results = array_merge($results, redisBenchmarks($options));
    }
    assertHotLoopCounters($results);

    echo json_encode([
        'environment' => benchmarkEnvironment($options),
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
