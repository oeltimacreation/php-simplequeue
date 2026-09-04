<?php

declare(strict_types=1);

const BENCHMARK_COUNTER_METRICS = [
    'db_queries',
    'db_transactions',
    'driver_roundtrips',
    'redis_commands',
    'redis_roundtrips',
    'redis_wire_bytes',
    'event_deliveries',
];

/** @param list<float|int> $values */
function median(array $values): float
{
    if ($values === []) {
        throw new InvalidArgumentException('Cannot calculate the median of an empty sample');
    }
    sort($values, SORT_NUMERIC);
    $middle = intdiv(count($values), 2);
    return count($values) % 2 === 0
        ? ((float) $values[$middle - 1] + (float) $values[$middle]) / 2
        : (float) $values[$middle];
}

/**
 * Measure process CPU time consumed between two getrusage() snapshots.
 *
 * @param array<string, mixed> $start getrusage() result captured before the operation
 * @param array<string, mixed> $end getrusage() result captured after the operation
 * @return float CPU seconds consumed (user plus system)
 */
function cpuSeconds(array $start, array $end): float
{
    $user = static fn (array $usage): float => (float) $usage['ru_utime.tv_sec']
        + ((float) $usage['ru_utime.tv_usec'] / 1_000_000);
    $system = static fn (array $usage): float => (float) $usage['ru_stime.tv_sec']
        + ((float) $usage['ru_stime.tv_usec'] / 1_000_000);
    return ($user($end) - $user($start)) + ($system($end) - $system($start));
}

/**
 * Read a numeric operation metric or fail with a useful benchmark error.
 *
 * @param array<string, int|float|Closure> $metrics Operation metrics
 */
function benchmarkNumericMetric(array $metrics, string $name, int|float $default = 0): int|float
{
    $value = $metrics[$name] ?? $default;
    if (!is_int($value) && !is_float($value)) {
        throw new UnexpectedValueException("Benchmark metric {$name} must be numeric");
    }

    return $value;
}

/**
 * @param Closure(): (Closure(): array<string, int|float|Closure>) $setup
 * @return array<string, mixed>
 */
function benchmark(BenchmarkScenario $scenario, BenchmarkOptions $options, Closure $setup): array
{
    $samples = [];
    for ($iteration = -$options->warmup; $iteration < $options->iterations; $iteration++) {
        $sample = benchmarkSample($setup);
        if ($iteration >= 0) {
            $samples[] = $sample;
        }
    }

    if ($samples === []) {
        throw new LogicException('Benchmark produced no measured samples');
    }

    return benchmarkSummary($scenario, $samples);
}

/**
 * @param Closure(): (Closure(): array<string, int|float|Closure>) $setup
 * @return array<string, int|float>
 */
function benchmarkSample(Closure $setup): array
{
    $operation = $setup();
    gc_collect_cycles();
    memory_reset_peak_usage();
    $memoryBefore = memory_get_usage(false);
    $cpuBefore = function_exists('getrusage') ? getrusage() : false;
    $started = hrtime(true);
    $metrics = $operation();
    $seconds = (hrtime(true) - $started) / 1_000_000_000;
    $cpuAfter = function_exists('getrusage') ? getrusage() : false;
    $measuredCpuSeconds = cpuSecondsWhenAvailable($cpuBefore, $cpuAfter);
    $operations = (int) benchmarkNumericMetric($metrics, 'operations');
    $sample = [
        'seconds' => $seconds,
        'throughput_per_second' => $operations / max($seconds, 0.000_000_001),
        'peak_memory_bytes' => max(0, memory_get_peak_usage(false) - $memoryBefore),
        'retained_memory_bytes' => memory_get_usage(false) - $memoryBefore,
        'operations' => $operations,
    ];
    foreach (BENCHMARK_COUNTER_METRICS as $metric) {
        $sample[$metric] = (int) benchmarkNumericMetric($metrics, $metric);
    }
    $sample['cpu_seconds'] = (float) benchmarkNumericMetric($metrics, 'cpu_seconds', $measuredCpuSeconds);
    benchmarkCleanup($metrics);
    return $sample;
}

/**
 * @param array<string, mixed>|false $start
 * @param array<string, mixed>|false $end
 */
function cpuSecondsWhenAvailable(array|false $start, array|false $end): float
{
    if (!is_array($start) || !is_array($end)) {
        return 0.0;
    }
    return cpuSeconds($start, $end);
}

/** @param array<string, int|float|Closure> $metrics */
function benchmarkCleanup(array $metrics): void
{
    $cleanup = $metrics['cleanup'] ?? null;
    if ($cleanup instanceof Closure) {
        $cleanup();
    }
}

/**
 * @param non-empty-list<array<string, int|float>> $samples
 * @return array<string, mixed>
 */
function benchmarkSummary(BenchmarkScenario $scenario, array $samples): array
{
    $seconds = array_map(static fn (array $sample): int|float => $sample['seconds'], $samples);
    $throughput = array_map(
        static fn (array $sample): int|float => $sample['throughput_per_second'],
        $samples
    );
    $peakMemory = array_map(static fn (array $sample): int|float => $sample['peak_memory_bytes'], $samples);
    $cpu = array_map(static fn (array $sample): int|float => $sample['cpu_seconds'], $samples);
    return [
        'name' => $scenario->value,
        'median_seconds' => median(array_column($samples, 'seconds')),
        'median_throughput_per_second' => median(array_column($samples, 'throughput_per_second')),
        'median_operations' => median(array_column($samples, 'operations')),
        'min_seconds' => min($seconds),
        'max_seconds' => max($seconds),
        'min_throughput_per_second' => min($throughput),
        'max_throughput_per_second' => max($throughput),
        'min_peak_memory_bytes' => min($peakMemory),
        'max_peak_memory_bytes' => max([0, ...array_column($samples, 'peak_memory_bytes')]),
        'median_retained_memory_bytes' => median(array_column($samples, 'retained_memory_bytes')),
        'median_db_queries' => median(array_column($samples, 'db_queries')),
        'median_db_transactions' => median(array_column($samples, 'db_transactions')),
        'median_driver_roundtrips' => median(array_column($samples, 'driver_roundtrips')),
        'median_redis_commands' => median(array_column($samples, 'redis_commands')),
        'median_redis_roundtrips' => median(array_column($samples, 'redis_roundtrips')),
        'median_redis_wire_bytes' => median(array_column($samples, 'redis_wire_bytes')),
        'median_event_deliveries' => median(array_column($samples, 'event_deliveries')),
        'min_cpu_seconds' => min($cpu),
        'max_cpu_seconds' => max($cpu),
        'median_cpu_seconds' => median(array_column($samples, 'cpu_seconds')),
        'samples' => $samples,
    ];
}
