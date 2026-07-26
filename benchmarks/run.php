<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Oeltima\SimpleQueue\Contract\ClockInterface;
use Oeltima\SimpleQueue\Contract\JobHandlerInterface;
use Oeltima\SimpleQueue\Driver\InMemoryQueueDriver;
use Oeltima\SimpleQueue\Driver\RedisQueueDriver;
use Oeltima\SimpleQueue\JobDispatcher;
use Oeltima\SimpleQueue\JobRegistry;
use Oeltima\SimpleQueue\QueueManager;
use Oeltima\SimpleQueue\QueueReconciler;
use Oeltima\SimpleQueue\ReconcileOptions;
use Oeltima\SimpleQueue\Storage\InMemoryJobStorage;
use Oeltima\SimpleQueue\Storage\PdoJobStorage;
use Oeltima\SimpleQueue\Worker;
use Predis\Client;
use Predis\ClientInterface;
use Predis\Command\CommandInterface;

final class BenchmarkPdo extends \PDO
{
    public int $queries = 0;
    public int $transactions = 0;

    public function prepare(string $query, array $options = []): \PDOStatement|false
    {
        $this->queries++;
        return parent::prepare($query, $options);
    }

    public function exec(string $statement): int|false
    {
        if (str_starts_with($statement, 'BEGIN')) {
            $this->transactions++;
            return parent::exec($statement);
        }
        if ($statement === 'COMMIT' || $statement === 'ROLLBACK') {
            return parent::exec($statement);
        }
        $this->queries++;
        return parent::exec($statement);
    }

    public function beginTransaction(): bool
    {
        $this->transactions++;
        return parent::beginTransaction();
    }

    public function resetCounts(): void
    {
        $this->queries = 0;
        $this->transactions = 0;
    }
}

final class BenchmarkRedisClient implements ClientInterface
{
    public int $commands = 0;
    public int $roundTrips = 0;

    public function __construct(public readonly ClientInterface $inner)
    {
    }

    public function getCommandFactory()
    {
        return $this->inner->getCommandFactory();
    }

    public function getOptions()
    {
        return $this->inner->getOptions();
    }

    public function connect(): void
    {
        $this->inner->connect();
    }

    public function disconnect(): void
    {
        $this->inner->disconnect();
    }

    public function getConnection()
    {
        return $this->inner->getConnection();
    }

    public function createCommand($method, $arguments = [])
    {
        return $this->inner->createCommand($method, $arguments);
    }

    public function executeCommand(CommandInterface $command)
    {
        $this->commands++;
        $this->roundTrips++;
        return $this->inner->executeCommand($command);
    }

    public function __call($method, $arguments)
    {
        if ($method === 'pipeline') {
            return new BenchmarkRedisPipeline($this, $this->inner->pipeline(...$arguments));
        }
        $this->commands++;
        $this->roundTrips++;
        return $this->inner->{$method}(...$arguments);
    }

    public function resetCounts(): void
    {
        $this->commands = 0;
        $this->roundTrips = 0;
    }
}

final class BenchmarkRedisPipeline
{
    public function __construct(
        private readonly BenchmarkRedisClient $client,
        private readonly object $pipeline
    ) {
    }

    public function __call(string $method, array $arguments): self
    {
        $this->client->commands++;
        $this->pipeline->{$method}(...$arguments);
        return $this;
    }

    public function execute(): array
    {
        $this->client->roundTrips++;
        return $this->pipeline->execute();
    }
}

final class BenchmarkClock implements ClockInterface
{
    public int $monotonicReads = 0;
    private float $monotonicTime = 1.0;

    public function __construct(private readonly float $step)
    {
    }

    public function now(): string
    {
        return '2026-01-01 00:00:00';
    }

    public function timestamp(): int
    {
        return 1_767_225_600;
    }

    public function monotonic(): float
    {
        $this->monotonicReads++;
        $this->monotonicTime += $this->step;
        return $this->monotonicTime;
    }
}

final class NoopBenchmarkHandler implements JobHandlerInterface
{
    public function handle(int $jobId, array $payload, ?callable $progressCallback = null): array
    {
        return ['job_id' => $jobId];
    }
}

final class FailingBenchmarkHandler implements JobHandlerInterface
{
    public function handle(int $jobId, array $payload, ?callable $progressCallback = null): never
    {
        throw new RuntimeException('benchmark retry');
    }
}

/** @return array{BenchmarkPdo, PdoJobStorage} */
function sqliteStorage(?ClockInterface $clock = null): array
{
    $pdo = new BenchmarkPdo('sqlite::memory:', options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    $pdo->exec(<<<'SQL'
CREATE TABLE background_jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    queue TEXT NOT NULL DEFAULT 'default', type TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending', payload TEXT,
    attempts INTEGER NOT NULL DEFAULT 0, max_attempts INTEGER NOT NULL DEFAULT 3,
    progress INTEGER, progress_message TEXT, result TEXT, available_at TEXT NOT NULL,
    started_at TEXT, completed_at TEXT, locked_by TEXT, locked_at TEXT, lease_token TEXT,
    error_message TEXT, error_trace TEXT, request_id TEXT,
    created_at TEXT NOT NULL, updated_at TEXT NOT NULL
);
CREATE INDEX idx_claim_ready ON background_jobs (queue, status, available_at, id);
CREATE INDEX idx_status_available ON background_jobs (status, available_at);
CREATE INDEX idx_locked_at ON background_jobs (locked_at);
CREATE UNIQUE INDEX uq_active_request_id ON background_jobs (request_id)
    WHERE status IN ('pending', 'running');
SQL);
    $pdo->resetCounts();
    return [$pdo, new PdoJobStorage($pdo, 'background_jobs', $clock)];
}

/** @return list<array{index: int, value: string}> */
function payloads(int $jobs): array
{
    $payloads = [];
    for ($index = 0; $index < $jobs; $index++) {
        $payloads[] = ['index' => $index, 'value' => 'benchmark'];
    }
    return $payloads;
}

/** @param list<float|int> $values */
function median(array $values): float
{
    sort($values, SORT_NUMERIC);
    $middle = intdiv(count($values), 2);
    return count($values) % 2 === 0
        ? ((float) $values[$middle - 1] + (float) $values[$middle]) / 2
        : (float) $values[$middle];
}

/**
 * @param Closure(): Closure(): array<string, int|float|Closure> $setup
 * @return array<string, mixed>
 */
function benchmark(string $name, int $iterations, int $warmup, Closure $setup): array
{
    $samples = [];
    for ($iteration = -$warmup; $iteration < $iterations; $iteration++) {
        $operation = $setup();
        gc_collect_cycles();
        memory_reset_peak_usage();
        $memoryBefore = memory_get_usage(false);
        $started = hrtime(true);
        $metrics = $operation();
        $seconds = (hrtime(true) - $started) / 1_000_000_000;
        $sample = [
            'seconds' => $seconds,
            'throughput_per_second' => (float) $metrics['operations'] / max($seconds, 0.000_000_001),
            'peak_memory_bytes' => max(0, memory_get_peak_usage(false) - $memoryBefore),
            'retained_memory_bytes' => memory_get_usage(false) - $memoryBefore,
            'operations' => (int) $metrics['operations'],
            'db_queries' => (int) ($metrics['db_queries'] ?? 0),
            'db_transactions' => (int) ($metrics['db_transactions'] ?? 0),
            'redis_commands' => (int) ($metrics['redis_commands'] ?? 0),
            'redis_roundtrips' => (int) ($metrics['redis_roundtrips'] ?? 0),
        ];
        if (isset($metrics['cleanup']) && $metrics['cleanup'] instanceof Closure) {
            $metrics['cleanup']();
        }
        if ($iteration >= 0) {
            $samples[] = $sample;
        }
    }

    return [
        'name' => $name,
        'median_seconds' => median(array_column($samples, 'seconds')),
        'median_throughput_per_second' => median(array_column($samples, 'throughput_per_second')),
        'max_peak_memory_bytes' => max(array_column($samples, 'peak_memory_bytes')),
        'median_retained_memory_bytes' => median(array_column($samples, 'retained_memory_bytes')),
        'median_db_queries' => median(array_column($samples, 'db_queries')),
        'median_db_transactions' => median(array_column($samples, 'db_transactions')),
        'median_redis_commands' => median(array_column($samples, 'redis_commands')),
        'median_redis_roundtrips' => median(array_column($samples, 'redis_roundtrips')),
        'samples' => $samples,
    ];
}

/** @return array<string, int> */
function databaseCounts(BenchmarkPdo $pdo, int $operations): array
{
    return [
        'operations' => $operations,
        'db_queries' => $pdo->queries,
        'db_transactions' => $pdo->transactions,
    ];
}

/** @return list<array<string, mixed>> */
function localBenchmarks(int $jobs, int $iterations, int $warmup, int $idleCycles): array
{
    $payloads = payloads($jobs);
    return [
        benchmark('memory.dispatch_batch', $iterations, $warmup, static function () use ($payloads): Closure {
            $storage = new InMemoryJobStorage();
            $dispatcher = new JobDispatcher($storage, new QueueManager(new InMemoryQueueDriver()));
            return static function () use ($dispatcher, $payloads): array {
                $jobIds = $dispatcher->dispatchBatch('benchmark.noop', $payloads);
                return ['operations' => count($jobIds)];
            };
        }),
        benchmark('sqlite.dispatch_single', $iterations, $warmup, static function () use ($jobs): Closure {
            [$pdo, $storage] = sqliteStorage();
            $dispatcher = new JobDispatcher($storage, QueueManager::database($storage));
            return static function () use ($dispatcher, $pdo, $jobs): array {
                for ($index = 0; $index < $jobs; $index++) {
                    $dispatcher->dispatch('benchmark.noop', ['index' => $index]);
                }
                return databaseCounts($pdo, $jobs);
            };
        }),
        benchmark('sqlite.dispatch_batch', $iterations, $warmup, static function () use ($payloads, $jobs): Closure {
            [$pdo, $storage] = sqliteStorage();
            $dispatcher = new JobDispatcher($storage, QueueManager::database($storage));
            return static function () use ($dispatcher, $pdo, $payloads, $jobs): array {
                $dispatcher->dispatchBatch('benchmark.noop', $payloads);
                return databaseCounts($pdo, $jobs);
            };
        }),
        benchmark('sqlite.claim', $iterations, $warmup, static function () use ($payloads, $jobs): Closure {
            [$pdo, $storage] = sqliteStorage();
            $dispatcher = new JobDispatcher($storage, QueueManager::database($storage));
            $dispatcher->dispatchBatch('benchmark.noop', $payloads);
            $pdo->resetCounts();
            return static function () use ($storage, $pdo, $jobs): array {
                $claimed = 0;
                for ($index = 0; $index < $jobs; $index++) {
                    $claimed += $storage->claimNextAvailable('default', 'benchmark-worker') === null ? 0 : 1;
                }
                return databaseCounts($pdo, $claimed);
            };
        }),
        benchmark('worker.execute_ack', $iterations, $warmup, static function () use ($payloads, $jobs): Closure {
            [$pdo, $storage] = sqliteStorage();
            $driver = new InMemoryQueueDriver();
            (new JobDispatcher($storage, new QueueManager($driver)))->dispatchBatch('benchmark.noop', $payloads);
            $registry = new JobRegistry();
            $registry->register('benchmark.noop', NoopBenchmarkHandler::class);
            $worker = new Worker($storage, new QueueManager($driver), $registry, options: [
                'lock_file' => null,
                'poll_timeout' => 0,
            ]);
            $pdo->resetCounts();
            return static function () use ($worker, $pdo, $jobs): array {
                $processed = 0;
                for ($index = 0; $index < $jobs; $index++) {
                    $processed += $worker->processOne() ? 1 : 0;
                }
                return databaseCounts($pdo, $processed);
            };
        }),
        benchmark('worker.retry', $iterations, $warmup, static function () use ($payloads, $jobs): Closure {
            [$pdo, $storage] = sqliteStorage();
            $driver = new InMemoryQueueDriver();
            (new JobDispatcher($storage, new QueueManager($driver)))->dispatchBatch(
                'benchmark.fail',
                $payloads,
                maxAttempts: 2
            );
            $registry = new JobRegistry();
            $registry->register('benchmark.fail', FailingBenchmarkHandler::class);
            $worker = new Worker($storage, new QueueManager($driver), $registry, options: [
                'lock_file' => null,
                'poll_timeout' => 0,
                'retry_base_delay' => 60,
                'retry_max_delay' => 60,
            ]);
            $pdo->resetCounts();
            return static function () use ($worker, $pdo, $jobs): array {
                $processed = 0;
                for ($index = 0; $index < $jobs; $index++) {
                    $processed += $worker->processOne() ? 1 : 0;
                }
                return databaseCounts($pdo, $processed);
            };
        }),
        benchmark('sqlite.reconcile', $iterations, $warmup, static function () use ($payloads, $jobs): Closure {
            [$pdo, $storage] = sqliteStorage();
            (new JobDispatcher($storage, QueueManager::database($storage)))->dispatchBatch('benchmark.noop', $payloads);
            $driver = new InMemoryQueueDriver();
            $reconciler = new QueueReconciler($storage, $driver);
            $pdo->resetCounts();
            return static function () use ($reconciler, $pdo, $jobs): array {
                $result = $reconciler->reconcile('default', new ReconcileOptions(
                    pageSize: $jobs,
                    membershipScanLimit: $jobs,
                    maxDurationSeconds: 60.0
                ));
                return databaseCounts($pdo, $result->scanned);
            };
        }),
        benchmark('worker.idle_maintenance', $iterations, $warmup, static function () use ($idleCycles): Closure {
            $clock = new BenchmarkClock(1.0 / $idleCycles);
            [$pdo, $storage] = sqliteStorage($clock);
            $driver = new InMemoryQueueDriver($clock);
            $worker = new Worker($storage, new QueueManager($driver), new JobRegistry(), options: [
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
                return databaseCounts($pdo, $clock->monotonicReads);
            };
        }),
    ];
}

/** @return list<array<string, mixed>> */
function redisBenchmarks(string $host, int $port, int $jobs, int $iterations, int $warmup): array
{
    $redisJobs = min($jobs, 100);
    $setup = static function (string $scenario) use ($host, $port): array {
        $inner = new Client(['scheme' => 'tcp', 'host' => $host, 'port' => $port]);
        $inner->connect();
        $client = new BenchmarkRedisClient($inner);
        $prefix = sprintf('sq-benchmark:%s:%s', $scenario, bin2hex(random_bytes(6)));
        return [$inner, $client, new RedisQueueDriver($client, $prefix), $prefix];
    };
    $cleanup = static function (Client $client, string $prefix): Closure {
        return static function () use ($client, $prefix): void {
            $keys = $client->keys($prefix . ':*');
            if ($keys !== []) {
                $client->del($keys);
            }
        };
    };

    return [
        benchmark('redis.dispatch_batch', $iterations, $warmup, static function () use (
            $setup,
            $cleanup,
            $redisJobs
        ): Closure {
            [$inner, $client, $driver, $prefix] = $setup('batch');
            $client->resetCounts();
            return static function () use ($inner, $client, $driver, $prefix, $cleanup, $redisJobs): array {
                $driver->enqueueBatch('default', range(1, $redisJobs));
                return [
                    'operations' => $redisJobs,
                    'redis_commands' => $client->commands,
                    'redis_roundtrips' => $client->roundTrips,
                    'cleanup' => $cleanup($inner, $prefix),
                ];
            };
        }),
        benchmark('redis.dequeue_ack', $iterations, $warmup, static function () use (
            $setup,
            $cleanup,
            $redisJobs
        ): Closure {
            [$inner, $client, $driver, $prefix] = $setup('ack');
            $driver->enqueueBatch('default', range(1, $redisJobs));
            $client->resetCounts();
            return static function () use ($inner, $client, $driver, $prefix, $cleanup, $redisJobs): array {
                $processed = 0;
                while (($jobId = $driver->dequeue('default', 0)) !== null) {
                    $driver->ack('default', $jobId);
                    $processed++;
                }
                return [
                    'operations' => $processed,
                    'redis_commands' => $client->commands,
                    'redis_roundtrips' => $client->roundTrips,
                    'cleanup' => $cleanup($inner, $prefix),
                ];
            };
        }),
        benchmark('redis.retry', $iterations, $warmup, static function () use (
            $setup,
            $cleanup,
            $redisJobs
        ): Closure {
            [$inner, $client, $driver, $prefix] = $setup('retry');
            $driver->enqueueBatch('default', range(1, $redisJobs));
            $client->resetCounts();
            return static function () use ($inner, $client, $driver, $prefix, $cleanup, $redisJobs): array {
                $processed = 0;
                for ($index = 0; $index < $redisJobs; $index++) {
                    $jobId = $driver->dequeue('default', 0);
                    if ($jobId !== null) {
                        $driver->nack('default', $jobId, 60);
                        $processed++;
                    }
                }
                return [
                    'operations' => $processed,
                    'redis_commands' => $client->commands,
                    'redis_roundtrips' => $client->roundTrips,
                    'cleanup' => $cleanup($inner, $prefix),
                ];
            };
        }),
        benchmark('redis.repair_unscored', $iterations, $warmup, static function () use (
            $setup,
            $cleanup,
            $redisJobs
        ): Closure {
            [$inner, $client, $driver, $prefix] = $setup('repair');
            $processing = "{$prefix}:queue:default:processing";
            $inner->lpush($processing, array_map('strval', range(1, $redisJobs)));
            $client->resetCounts();
            return static function () use ($inner, $client, $driver, $prefix, $cleanup, $redisJobs): array {
                $driver->recoverStaleProcessing('default', 600, $redisJobs);
                return [
                    'operations' => $redisJobs,
                    'redis_commands' => $client->commands,
                    'redis_roundtrips' => $client->roundTrips,
                    'cleanup' => $cleanup($inner, $prefix),
                ];
            };
        }),
    ];
}

$options = getopt('', ['jobs::', 'iterations::', 'warmup::', 'idle-cycles::', 'redis-host::', 'redis-port::']);
$jobs = max(1, (int) ($options['jobs'] ?? 1_000));
$iterations = max(1, (int) ($options['iterations'] ?? 5));
$warmup = max(0, (int) ($options['warmup'] ?? 1));
$idleCycles = max(10, (int) ($options['idle-cycles'] ?? 500));
$redisHost = $options['redis-host'] ?? getenv('REDIS_HOST') ?: null;
$redisPort = (int) ($options['redis-port'] ?? getenv('REDIS_PORT') ?: 6379);

$results = localBenchmarks($jobs, $iterations, $warmup, $idleCycles);
if (is_string($redisHost) && $redisHost !== '') {
    $results = array_merge($results, redisBenchmarks($redisHost, $redisPort, $jobs, $iterations, $warmup));
}

echo json_encode([
    'environment' => [
        'php' => PHP_VERSION,
        'platform' => php_uname('s') . ' ' . php_uname('m'),
        'pdo_drivers' => \PDO::getAvailableDrivers(),
        'redis' => $redisHost === null ? null : "{$redisHost}:{$redisPort}",
    ],
    'configuration' => [
        'jobs' => $jobs,
        'iterations' => $iterations,
        'warmup' => $warmup,
        'idle_cycles' => $idleCycles,
    ],
    'results' => $results,
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
