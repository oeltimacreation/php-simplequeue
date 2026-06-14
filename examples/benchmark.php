<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Oeltima\SimpleQueue\Storage\PdoJobStorage;
use Oeltima\SimpleQueue\Driver\RedisQueueDriver;
use Oeltima\SimpleQueue\QueueManager;
use Oeltima\SimpleQueue\JobDispatcher;
use Oeltima\SimpleQueue\Tests\DbHelper;
use Predis\Client;

// Custom Statement to intercept execution count
class InstrumentedPdoStatement extends PDOStatement
{
    private InstrumentedPdo $pdo;

    protected function __construct(InstrumentedPdo $pdo)
    {
        $this->pdo = $pdo;
    }

    public function execute(?array $params = null): bool
    {
        $this->pdo->executeCount++;
        return parent::execute($params);
    }
}

// Custom PDO to track operations
class InstrumentedPdo extends PDO
{
    public int $executeCount = 0;
    public int $queryCount = 0;
    public int $execCount = 0;

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->queryCount++;
        return parent::query($query, $fetchMode, ...$fetchModeArgs);
    }

    public function exec(string $statement): int|false
    {
        $this->execCount++;
        return parent::exec($statement);
    }
}

function runDbBenchmark(int $jobCount): void
{
    echo "==================================================\n";
    echo "Running Database (SQLite In-Memory) Benchmark...\n";
    echo "==================================================\n";

    $pdo = new InstrumentedPdo('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [InstrumentedPdoStatement::class, [$pdo]]);

    DbHelper::createSchema($pdo);

    $storage = new PdoJobStorage($pdo);
    // Using QueueManager with DatabaseQueueDriver
    $queueManager = QueueManager::database($storage);
    $dispatcher = new JobDispatcher($storage, $queueManager);

    // Reset counters
    $pdo->executeCount = 0;
    $pdo->queryCount = 0;
    $pdo->execCount = 0;

    // 1. Dispatch Benchmark
    $start = microtime(true);
    for ($i = 0; $i < $jobCount; $i++) {
        $dispatcher->dispatch('test.job', ['index' => $i], 'default');
    }
    $dispatchTime = microtime(true) - $start;
    $dispatchQueries = $pdo->executeCount + $pdo->queryCount + $pdo->execCount;

    // 2. Claim Benchmark
    $pdo->executeCount = 0;
    $pdo->queryCount = 0;
    $pdo->execCount = 0;
    $claims = [];
    $start = microtime(true);
    for ($i = 0; $i < $jobCount; $i++) {
        $claims[] = $storage->claimNextAvailable('default', 'benchmark-worker');
    }
    $claimTime = microtime(true) - $start;
    $claimQueries = $pdo->executeCount + $pdo->queryCount + $pdo->execCount;

    // 3. Complete Benchmark
    $pdo->executeCount = 0;
    $pdo->queryCount = 0;
    $pdo->execCount = 0;
    $start = microtime(true);
    foreach ($claims as $claim) {
        if ($claim) {
            $storage->markCompleted($claim, ['result' => 'ok']);
        }
    }
    $completeTime = microtime(true) - $start;
    $completeQueries = $pdo->executeCount + $pdo->queryCount + $pdo->execCount;

    printf("Jobs processed:     %d\n", $jobCount);
    printf("Dispatch:           %.4f seconds (%d jobs/sec, %.1f queries/job)\n", 
        $dispatchTime, 
        (int)($jobCount / $dispatchTime),
        $dispatchQueries / $jobCount
    );
    printf("Claim/Dequeue:      %.4f seconds (%d jobs/sec, %.1f queries/job)\n", 
        $claimTime, 
        (int)($jobCount / $claimTime),
        $claimQueries / $jobCount
    );
    printf("Mark Completed:     %.4f seconds (%d jobs/sec, %.1f queries/job)\n", 
        $completeTime, 
        (int)($jobCount / $completeTime),
        $completeQueries / $jobCount
    );
    printf("Total DB Round-trips per Job: %.1f queries\n\n", 
        ($dispatchQueries + $claimQueries + $completeQueries) / $jobCount
    );
}

function runRedisBenchmark(int $jobCount): void
{
    $redisHost = getenv('REDIS_HOST') ?: '127.0.0.1';
    $redisPort = (int)(getenv('REDIS_PORT') ?: '6379');

    echo "==================================================\n";
    echo "Running Redis Benchmark...\n";
    echo "==================================================\n";

    try {
        $client = new Client([
            'scheme' => 'tcp',
            'host' => $redisHost,
            'port' => $redisPort,
        ]);
        $client->connect();
    } catch (\Exception $e) {
        echo "Redis not available ($redisHost:$redisPort). Skipping Redis benchmark.\n\n";
        return;
    }

    $driver = new RedisQueueDriver($client, 'benchmark');
    $driver->clear('default');

    // 1. Dispatch Benchmark (Enqueue)
    $start = microtime(true);
    for ($i = 0; $i < $jobCount; $i++) {
        $driver->enqueue('default', $i + 1);
    }
    $dispatchTime = microtime(true) - $start;

    // 2. Claim Benchmark (Dequeue)
    $start = microtime(true);
    for ($i = 0; $i < $jobCount; $i++) {
        $driver->dequeue('default', 0);
    }
    $claimTime = microtime(true) - $start;

    // 3. Complete Benchmark (Ack)
    $start = microtime(true);
    for ($i = 0; $i < $jobCount; $i++) {
        $driver->ack('default', $i + 1);
    }
    $completeTime = microtime(true) - $start;

    $driver->clear('default');

    printf("Jobs processed:     %d\n", $jobCount);
    printf("Enqueue (Dispatch): %.4f seconds (%d jobs/sec)\n", 
        $dispatchTime, 
        (int)($jobCount / $dispatchTime)
    );
    printf("Dequeue (Claim):    %.4f seconds (%d jobs/sec)\n", 
        $claimTime, 
        (int)($jobCount / $claimTime)
    );
    printf("Ack (Complete):     %.4f seconds (%d jobs/sec)\n", 
        $completeTime, 
        (int)($jobCount / $completeTime)
    );
    echo "\n";
}

$jobCount = 1000;
runDbBenchmark($jobCount);
runRedisBenchmark($jobCount);
