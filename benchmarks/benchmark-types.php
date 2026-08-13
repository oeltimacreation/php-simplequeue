<?php

declare(strict_types=1);

use Oeltima\SimpleQueue\Contract\ClockInterface;
use Oeltima\SimpleQueue\Contract\QueueDriverInterface;
use Oeltima\SimpleQueue\Driver\InMemoryQueueDriver;

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
        if (in_array($statement, ['COMMIT', 'ROLLBACK'], true)) {
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

final class BenchmarkQueueDriver implements QueueDriverInterface
{
    public function __construct(
        private readonly InMemoryQueueDriver $inner,
        private readonly BenchmarkCounter $counter = new BenchmarkCounter()
    )
    {
    }

    public function isAvailable(): true
    {
        return $this->inner->isAvailable();
    }

    public function enqueue($queue, $jobId): void
    {
        $this->counter->increment();
        $this->inner->enqueue($queue, $jobId);
    }

    public function dequeue($queue, $timeoutSeconds): ?int
    {
        $this->counter->increment();

        return $this->inner->dequeue($queue, $timeoutSeconds);
    }

    public function ack($queue, $jobId): void
    {
        $this->counter->increment();
        $this->inner->ack($queue, $jobId);
    }

    public function nack($queue, $jobId, $delaySeconds = 0): void
    {
        $this->counter->increment();
        $this->inner->nack($queue, $jobId, $delaySeconds);
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
