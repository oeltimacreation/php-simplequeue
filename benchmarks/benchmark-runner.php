<?php

declare(strict_types=1);

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
