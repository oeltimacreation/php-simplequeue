<?php

declare(strict_types=1);

use Oeltima\SimpleQueue\Contract\ClockInterface;
use Oeltima\SimpleQueue\Contract\QueueDriverInterface;
use Oeltima\SimpleQueue\Contract\SupportsBatchQueueReconciliation;
use Oeltima\SimpleQueue\Contract\SupportsBoundedQueueMembership;
use Oeltima\SimpleQueue\Driver\InMemoryQueueDriver;

final class BenchmarkPdo extends \PDO
{
    public int $queries = 0;
    public int $transactions = 0;
    public int $prepares = 0;
    public int $executions = 0;
    public int $fetchedJsonBytes = 0;

    /** @param array<int, mixed>|null $options PDO connection options */
    public function __construct(
        string $dsn,
        ?string $username = null,
        #[\SensitiveParameter] ?string $password = null,
        ?array $options = null
    ) {
        parent::__construct($dsn, $username, $password, $options);
        $this->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [BenchmarkStatement::class, [$this]]);
    }

    /** @param array<array-key, mixed> $options Driver-specific statement options */
    public function prepare(string $query, array $options = []): \PDOStatement|false
    {
        $this->prepares++;
        $this->queries++;
        return parent::prepare($query, $options);
    }

    public function exec(string $statement): int|false
    {
        if (str_starts_with($statement, 'BEGIN')) {
            $this->transactions++;
            return parent::exec($statement);
        }
        if (in_array($statement, ['COMMIT', 'ROLLBACK'], true)) {
            return parent::exec($statement);
        }
        $this->queries++;
        $this->executions++;
        return parent::exec($statement);
    }

    /** @param mixed ...$fetchModeArgs Fetch mode arguments */
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false
    {
        $this->executions++;
        return parent::query($query, $fetchMode, ...$fetchModeArgs);
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
        $this->prepares = 0;
        $this->executions = 0;
        $this->fetchedJsonBytes = 0;
    }
}

final class BenchmarkStatement extends \PDOStatement
{
    protected function __construct(private readonly BenchmarkPdo $counter)
    {
    }

    /** @param array<array-key, mixed>|null $params Statement parameters */
    public function execute(?array $params = null): bool
    {
        $this->counter->executions++;
        return parent::execute($params);
    }

    public function fetch(
        int $mode = \PDO::FETCH_DEFAULT,
        int $cursorOrientation = \PDO::FETCH_ORI_NEXT,
        int $cursorOffset = 0
    ): mixed {
        $row = parent::fetch($mode, $cursorOrientation, $cursorOffset);
        $this->recordRow($row);
        return $row;
    }

    /**
     * @param mixed ...$args Fetch mode arguments
     * @return array<mixed> Fetched rows
     */
    public function fetchAll(int $mode = \PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        $rows = parent::fetchAll($mode, ...$args);
        foreach ($rows as $row) {
            $this->recordRow($row);
        }
        return $rows;
    }

    private function recordRow(mixed $row): void
    {
        if (!is_array($row)) {
            return;
        }
        foreach (['payload', 'result'] as $field) {
            $value = $row[$field] ?? null;
            if (is_string($value)) {
                $this->counter->fetchedJsonBytes += strlen($value);
            }
        }
    }
}

final class BenchmarkCounter
{
    private int $value = 0;

    public function increment(): void
    {
        $this->value++;
    }

    public function value(): int
    {
        return $this->value;
    }

    public function reset(): void
    {
        $this->value = 0;
    }
}

final class BenchmarkQueueDriver implements
    QueueDriverInterface,
    SupportsBatchQueueReconciliation,
    SupportsBoundedQueueMembership
{
    public function __construct(
        private readonly InMemoryQueueDriver $inner,
        private readonly BenchmarkCounter $counter = new BenchmarkCounter()
    ) {
    }

    public function isAvailable(): true
    {
        return $this->inner->isAvailable();
    }

    public function enqueue(string $queue, int $jobId): void
    {
        $this->counter->increment();
        $this->inner->enqueue($queue, $jobId);
    }

    public function dequeue(string $queue, int $timeoutSeconds): ?int
    {
        $this->counter->increment();

        return $this->inner->dequeue($queue, $timeoutSeconds);
    }

    public function ack(string $queue, int $jobId): void
    {
        $this->counter->increment();
        $this->inner->ack($queue, $jobId);
    }

    public function nack(string $queue, int $jobId, int $delaySeconds = 0): void
    {
        $this->counter->increment();
        $this->inner->nack($queue, $jobId, $delaySeconds);
    }

    public function hasPendingJob(string $queue, int $jobId, int $maxElements): bool
    {
        $this->counter->increment();
        return $this->inner->hasPendingJob($queue, $jobId, $maxElements);
    }

    public function hasDelayedJob(string $queue, int $jobId): bool
    {
        $this->counter->increment();
        return $this->inner->hasDelayedJob($queue, $jobId);
    }

    /**
     * @param array<int, int> $availableAtByJobId Job availability indexed by ID
     * @return list<int> IDs already represented by a notification
     */
    public function reconcileNotifications(
        string $queue,
        array $availableAtByJobId,
        int $now,
        int $pendingScanLimit
    ): array {
        $this->counter->increment();
        return $this->inner->reconcileNotifications($queue, $availableAtByJobId, $now, $pendingScanLimit);
    }

    public function roundTrips(): int
    {
        return $this->counter->value();
    }

    public function resetCounts(): void
    {
        $this->counter->reset();
    }
}

/** Exposes the legacy per-item reconciliation contract around the same counter. */
final readonly class BenchmarkFallbackQueueDriver implements QueueDriverInterface, SupportsBoundedQueueMembership
{
    public function __construct(private BenchmarkQueueDriver $inner)
    {
    }

    public function isAvailable(): bool
    {
        return $this->inner->isAvailable();
    }

    public function enqueue(string $queue, int $jobId): void
    {
        $this->inner->enqueue($queue, $jobId);
    }

    public function dequeue(string $queue, int $timeoutSeconds): ?int
    {
        return $this->inner->dequeue($queue, $timeoutSeconds);
    }

    public function ack(string $queue, int $jobId): void
    {
        $this->inner->ack($queue, $jobId);
    }

    public function nack(string $queue, int $jobId, int $delaySeconds = 0): void
    {
        $this->inner->nack($queue, $jobId, $delaySeconds);
    }

    public function hasPendingJob(string $queue, int $jobId, int $maxElements): bool
    {
        return $this->inner->hasPendingJob($queue, $jobId, $maxElements);
    }

    public function hasDelayedJob(string $queue, int $jobId): bool
    {
        return $this->inner->hasDelayedJob($queue, $jobId);
    }

    public function roundTrips(): int
    {
        return $this->inner->roundTrips();
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

final class BenchmarkScenario
{
    public readonly string $value;

    /** @param array{value: string} $definition */
    private function __construct(array $definition)
    {
        $this->value = $definition['value'];
    }

    /** @param array{value: string} $definition */
    public static function named(array $definition): self
    {
        return new self($definition);
    }

    public function sameAs(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function key(): string
    {
        return str_replace('.', '-', $this->value);
    }
}

final class BenchmarkOptions
{
    public int $jobs;
    public int $iterations;
    public int $warmup;
    public int $idleCycles;
    public ?string $redisHost;
    public int $redisPort;
    public bool $profile;

    public static function fromCli(): self
    {
        $input = getopt('', [
            'jobs::',
            'iterations::',
            'warmup::',
            'idle-cycles::',
            'redis-host::',
            'redis-port::',
            'profile',
        ]);
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
        $options->profile = array_key_exists('profile', $input);
        return $options;
    }

    /**
     * Return a copy configured for one profile scale.
     *
     * @param int $jobs Number of jobs in each scaled scenario
     * @return self Scaled benchmark options
     */
    public function withJobs(int $jobs): self
    {
        $copy = clone $this;
        $copy->jobs = $jobs;
        return $copy;
    }
}
