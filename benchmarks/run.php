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

    private readonly float $step;

    public function __construct(BenchmarkOptions $options)
    {
        $this->step = 1.0 / $options->idleCycles;
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

enum BenchmarkScenario: string
{
    case MemoryBatch = 'memory.dispatch_batch';
    case SqliteSingle = 'sqlite.dispatch_single';
    case SqliteBatch = 'sqlite.dispatch_batch';
    case SqliteClaim = 'sqlite.claim';
    case WorkerExecute = 'worker.execute_ack';
    case WorkerRetry = 'worker.retry';
    case SqliteReconcile = 'sqlite.reconcile';
    case IdleMaintenance = 'worker.idle_maintenance';
    case RedisBatch = 'redis.dispatch_batch';
    case RedisAck = 'redis.dequeue_ack';
    case RedisRetry = 'redis.retry';
    case RedisRepair = 'redis.repair_unscored';
}

final class BenchmarkOptions
{
    public int $jobs;
    public int $iterations;
    public int $warmup;
    public int $idleCycles;
    public ?string $redisHost;
    public int $redisPort;

    public static function fromCli(): self
    {
        $input = getopt('', ['jobs::', 'iterations::', 'warmup::', 'idle-cycles::', 'redis-host::', 'redis-port::']);
        $options = new self();
        $options->jobs = max(1, (int) ($input['jobs'] ?? 1_000));
        $options->iterations = max(1, (int) ($input['iterations'] ?? 5));
        $options->warmup = max(0, (int) ($input['warmup'] ?? 1));
        $options->idleCycles = max(10, (int) ($input['idle-cycles'] ?? 500));
        $redisHost = $input['redis-host'] ?? getenv('REDIS_HOST');
        $options->redisHost = is_string($redisHost) ? $redisHost : null;
        if ($options->redisHost === '') {
            $options->redisHost = null;
        }
        $redisPort = $input['redis-port'] ?? getenv('REDIS_PORT');
        $options->redisPort = is_numeric($redisPort) ? (int) $redisPort : 6379;
        return $options;
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
function payloads(BenchmarkOptions $options): array
{
    $payloads = [];
    for ($index = 0; $index < $options->jobs; $index++) {
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
 * @param Closure(): (Closure(): array<string, int|float|Closure>) $setup
 * @return array<string, mixed>
 */
function benchmark(BenchmarkScenario $scenario, BenchmarkOptions $options, Closure $setup): array
{
    $samples = [];
    for ($iteration = -$options->warmup; $iteration < $options->iterations; $iteration++) {
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
        'name' => $scenario->value,
        'median_seconds' => median(array_column($samples, 'seconds')),
        'median_throughput_per_second' => median(array_column($samples, 'throughput_per_second')),
        'max_peak_memory_bytes' => max([0, ...array_column($samples, 'peak_memory_bytes')]),
        'median_retained_memory_bytes' => median(array_column($samples, 'retained_memory_bytes')),
        'median_db_queries' => median(array_column($samples, 'db_queries')),
        'median_db_transactions' => median(array_column($samples, 'db_transactions')),
        'median_redis_commands' => median(array_column($samples, 'redis_commands')),
        'median_redis_roundtrips' => median(array_column($samples, 'redis_roundtrips')),
        'samples' => $samples,
    ];
}

/**
 * @param array{operations: int} $operation
 * @return array<string, int>
 */
function databaseCounts(BenchmarkPdo $pdo, array $operation): array
{
    return [
        'operations' => $operation['operations'],
        'db_queries' => $pdo->queries,
        'db_transactions' => $pdo->transactions,
    ];
}

/** @return list<array<string, mixed>> */
function localBenchmarks(BenchmarkOptions $options): array
{
    return [
        memoryBatchBenchmark($options),
        sqliteSingleBenchmark($options),
        sqliteBatchBenchmark($options),
        sqliteClaimBenchmark($options),
        workerExecutionBenchmark($options),
        workerRetryBenchmark($options),
        sqliteReconcileBenchmark($options),
        idleMaintenanceBenchmark($options),
    ];
}

/** @return array<string, mixed> */
function memoryBatchBenchmark(BenchmarkOptions $options): array
{
    $payloads = payloads($options);
    return benchmark(BenchmarkScenario::MemoryBatch, $options, static function () use ($payloads): Closure {
        $dispatcher = new JobDispatcher(new InMemoryJobStorage(), new QueueManager(new InMemoryQueueDriver()));
        return static function () use ($dispatcher, $payloads): array {
            return ['operations' => count($dispatcher->dispatchBatch('benchmark.noop', $payloads))];
        };
    });
}

/** @return array<string, mixed> */
function sqliteSingleBenchmark(BenchmarkOptions $options): array
{
    return benchmark(BenchmarkScenario::SqliteSingle, $options, static function () use ($options): Closure {
        [$pdo, $storage] = sqliteStorage();
        $dispatcher = new JobDispatcher($storage, QueueManager::database($storage));
        return static function () use ($dispatcher, $pdo, $options): array {
            for ($index = 0; $index < $options->jobs; $index++) {
                $dispatcher->dispatch('benchmark.noop', ['index' => $index]);
            }
            return databaseCounts($pdo, ['operations' => $options->jobs]);
        };
    });
}

/** @return array<string, mixed> */
function sqliteBatchBenchmark(BenchmarkOptions $options): array
{
    $payloads = payloads($options);
    return benchmark(BenchmarkScenario::SqliteBatch, $options, static function () use ($payloads, $options): Closure {
        [$pdo, $storage] = sqliteStorage();
        $dispatcher = new JobDispatcher($storage, QueueManager::database($storage));
        return static function () use ($dispatcher, $pdo, $payloads, $options): array {
            $dispatcher->dispatchBatch('benchmark.noop', $payloads);
            return databaseCounts($pdo, ['operations' => $options->jobs]);
        };
    });
}

/** @return array<string, mixed> */
function sqliteClaimBenchmark(BenchmarkOptions $options): array
{
    return benchmark(BenchmarkScenario::SqliteClaim, $options, static function () use ($options): Closure {
        [$pdo, $storage] = sqliteStorage();
        (new JobDispatcher($storage, QueueManager::database($storage)))->dispatchBatch('benchmark.noop', payloads($options));
        $pdo->resetCounts();
        return static function () use ($storage, $pdo, $options): array {
            $claimed = 0;
            for ($index = 0; $index < $options->jobs; $index++) {
                $claimed += $storage->claimNextAvailable('default', 'benchmark-worker') === null ? 0 : 1;
            }
            return databaseCounts($pdo, ['operations' => $claimed]);
        };
    });
}

/** @return array<string, mixed> */
function workerExecutionBenchmark(BenchmarkOptions $options): array
{
    return workerBenchmark($options, BenchmarkScenario::WorkerExecute);
}

/** @return array<string, mixed> */
function workerRetryBenchmark(BenchmarkOptions $options): array
{
    return workerBenchmark($options, BenchmarkScenario::WorkerRetry);
}

/** @return array<string, mixed> */
function workerBenchmark(BenchmarkOptions $options, BenchmarkScenario $scenario): array
{
    $retry = $scenario === BenchmarkScenario::WorkerRetry;
    $handler = $retry ? FailingBenchmarkHandler::class : NoopBenchmarkHandler::class;
    $jobType = $retry ? 'benchmark.fail' : 'benchmark.noop';
    $workerOptions = $retry ? ['retry_base_delay' => 60, 'retry_max_delay' => 60] : [];
    return benchmark($scenario, $options, static function () use ($options, $handler, $jobType, $workerOptions): Closure {
        [$pdo, $storage] = sqliteStorage();
        $driver = new InMemoryQueueDriver();
        (new JobDispatcher($storage, new QueueManager($driver)))->dispatchBatch(
            $jobType,
            payloads($options),
            maxAttempts: 2
        );
        $registry = new JobRegistry();
        $registry->register($jobType, $handler);
        $worker = new Worker($storage, new QueueManager($driver), $registry, options: array_merge([
            'lock_file' => null,
            'poll_timeout' => 0,
        ], $workerOptions));
        $pdo->resetCounts();
        return static function () use ($worker, $pdo, $options): array {
            $processed = 0;
            for ($index = 0; $index < $options->jobs; $index++) {
                $processed += $worker->processOne() ? 1 : 0;
            }
            return databaseCounts($pdo, ['operations' => $processed]);
        };
    });
}

/** @return array<string, mixed> */
function sqliteReconcileBenchmark(BenchmarkOptions $options): array
{
    return benchmark(BenchmarkScenario::SqliteReconcile, $options, static function () use ($options): Closure {
        [$pdo, $storage] = sqliteStorage();
        (new JobDispatcher($storage, QueueManager::database($storage)))->dispatchBatch('benchmark.noop', payloads($options));
        $reconciler = new QueueReconciler($storage, new InMemoryQueueDriver());
        $pdo->resetCounts();
        return static function () use ($reconciler, $pdo, $options): array {
            $result = $reconciler->reconcile('default', new ReconcileOptions(
                pageSize: $options->jobs,
                membershipScanLimit: $options->jobs,
                maxDurationSeconds: 60.0
            ));
            return databaseCounts($pdo, ['operations' => $result->scanned]);
        };
    });
}

/** @return array<string, mixed> */
function idleMaintenanceBenchmark(BenchmarkOptions $options): array
{
    return benchmark(BenchmarkScenario::IdleMaintenance, $options, static function () use ($options): Closure {
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

/** @return list<array<string, mixed>> */
function redisBenchmarks(BenchmarkOptions $options): array
{
    return [
        redisBatchBenchmark($options),
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
    $prefix = sprintf('sq-benchmark:%s:%s', $scenario->name, bin2hex(random_bytes(6)));
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

/** @return array<string, mixed> */
function redisBatchBenchmark(BenchmarkOptions $options): array
{
    return benchmark(BenchmarkScenario::RedisBatch, $options, static function () use ($options): Closure {
        $fixture = redisSetup($options, BenchmarkScenario::RedisBatch);
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
    return benchmark(BenchmarkScenario::RedisAck, $options, static function () use ($options): Closure {
        $fixture = redisSetup($options, BenchmarkScenario::RedisAck);
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
    return benchmark(BenchmarkScenario::RedisRetry, $options, static function () use ($options): Closure {
        $fixture = redisSetup($options, BenchmarkScenario::RedisRetry);
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
    return benchmark(BenchmarkScenario::RedisRepair, $options, static function () use ($options): Closure {
        $fixture = redisSetup($options, BenchmarkScenario::RedisRepair);
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
        'cleanup' => redisCleanup($fixture),
    ];
}

$options = BenchmarkOptions::fromCli();
$results = localBenchmarks($options);
if ($options->redisHost !== null) {
    $results = array_merge($results, redisBenchmarks($options));
}

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
