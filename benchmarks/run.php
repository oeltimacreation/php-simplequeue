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
        'cpu' => benchmarkCpuModel(),
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
        $serverInfo = isset($server['Server']) && is_array($server['Server'])
            ? $server['Server']
            : $server;
        $environment['redis_server'] = [
            'name' => $serverInfo['server_name'] ?? null,
            'version' => $serverInfo['valkey_version'] ?? $serverInfo['redis_version'] ?? null,
        ];
    } finally {
        $client->disconnect();
    }

    return $environment;
}

function benchmarkCpuModel(): ?string
{
    $cpuInfo = @file_get_contents('/proc/cpuinfo');
    if (!is_string($cpuInfo)) {
        return null;
    }
    if (preg_match('/^model name\s*:\s*(.+)$/m', $cpuInfo, $matches) !== 1) {
        return null;
    }
    return trim($matches[1]);
}

/** @return list<array<string, mixed>> */
function benchmarkResults(BenchmarkOptions $options): array
{
    $results = localBenchmarks($options);
    if ($options->redisHost !== null) {
        $results = array_merge($results, redisBenchmarks($options));
    }
    assertHotLoopCounters($results);
    return $results;
}

function runBenchmarks(): void
{
    $options = BenchmarkOptions::fromCli();
    $report = [
        'environment' => benchmarkEnvironment($options),
        'configuration' => [
            'jobs' => $options->jobs,
            'iterations' => $options->iterations,
            'warmup' => $options->warmup,
            'idle_cycles' => $options->idleCycles,
            'profile' => $options->profile,
        ],
    ];
    if ($options->profile) {
        $profiles = [];
        $profileSizes = [10, 100, 1_000, 10_000];
        $report['configuration']['profile_sizes'] = $profileSizes;
        foreach ($profileSizes as $jobs) {
            $profiles[] = ['jobs' => $jobs, 'results' => benchmarkResults($options->withJobs($jobs))];
        }
        $report['profiles'] = $profiles;
    } else {
        $report['results'] = benchmarkResults($options);
    }

    echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
}

runBenchmarks();
