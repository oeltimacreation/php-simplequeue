<?php

declare(strict_types=1);

final readonly class OperationBudget
{
    public function __construct(
        public string $scenario,
        public string $metric,
        public string $comparison,
        public string $formula,
        public int $multiplier,
        public int $offset,
        public int $chunkSize,
        public bool $optional,
        public string $mechanism,
        public string $rationale
    ) {
    }

    public function limit(int $operations): int
    {
        return match ($this->formula) {
            'fixed' => $this->offset,
            'per_operation' => ($this->multiplier * $operations) + $this->offset,
            'chunked' => ($this->multiplier * (int) ceil($operations / $this->chunkSize)) + $this->offset,
            default => throw new LogicException('Unknown operation budget formula'),
        };
    }
}

/**
 * Assert deterministic hot-path operation budgets.
 *
 * Each row declares the measured mechanism, why it is bounded, whether the
 * bound is exact or a maximum, and how the expected count scales. Redis rows
 * are optional only because the service-free benchmark intentionally omits
 * Redis scenarios; a configured Redis/Valkey lane executes every one.
 *
 * @param list<array<string, mixed>> $results Benchmark scenario results
 */
function assertHotLoopCounters(array $results): void
{
    $byName = indexScenarios($results);
    foreach (operationBudgetDefinitions() as $budget) {
        if (!isset($byName[$budget->scenario])) {
            if ($budget->optional) {
                continue;
            }
            throw new RuntimeException("Missing benchmark scenario {$budget->scenario}");
        }
        $result = $byName[$budget->scenario];
        $actual = integerMetric($result, $budget->metric);
        $expected = $budget->limit(operationCount($result));
        $passes = $budget->comparison === 'exact' ? $actual === $expected : $actual <= $expected;
        if (!$passes) {
            throw new RuntimeException(sprintf(
                '%s %s is %d; expected %s %d (%s; %s)',
                $budget->scenario,
                $budget->metric,
                $actual,
                $budget->comparison,
                $expected,
                $budget->mechanism,
                $budget->rationale
            ));
        }
    }
}

/**
 * @return list<OperationBudget>
 */
function operationBudgetDefinitions(): array
{
    $budgets = [];
    $add = static function (
        string $scenario,
        string $metric,
        string $relation,
        array $scale,
        string $evidence
    ) use (&$budgets): void {
        if (count($scale) !== 3 || !is_int($scale[0]) || !is_int($scale[1]) || !is_int($scale[2])) {
            throw new LogicException('Operation budget scale must contain three integers');
        }
        [$comparison, $formulaName] = explode('-', $relation, 2);
        $formula = match ($formulaName) {
            'fixed' => 'fixed',
            'per' => 'per_operation',
            'chunk' => 'chunked',
            default => throw new LogicException('Unknown operation budget relation'),
        };
        $descriptions = explode(': ', $evidence, 2);
        if (count($descriptions) !== 2) {
            throw new LogicException('Operation budget evidence must contain a mechanism and rationale');
        }
        $budgets[] = new OperationBudget(
            $scenario,
            $metric,
            $comparison,
            $formula,
            $scale[0],
            $scale[1],
            $scale[2],
            str_starts_with($scenario, 'redis.'),
            $descriptions[0],
            $descriptions[1]
        );
    };

    foreach (['sqlite.dispatch_single', 'sqlite.dispatch_scheduled_single'] as $scenario) {
        $add($scenario, 'median_db_queries', 'exact-per', [1, 0, 1], 'INSERT: one row per dispatch');
    }
    foreach (['sqlite.dispatch_batch', 'sqlite.dispatch_scheduled_batch'] as $scenario) {
        $add($scenario, 'median_db_transactions', 'maximum-fixed', [0, 1, 1], 'transaction: one atomic batch');
        $add($scenario, 'median_db_queries', 'maximum-chunk', [1, 2, 100], 'INSERT: 100-row chunks');
    }
    $boundary = 'sqlite.dispatch_batch_chunk_boundary';
    $add($boundary, 'median_db_transactions', 'maximum-fixed', [0, 1, 1], 'transaction: 201 rows stay atomic');
    $add($boundary, 'median_db_queries', 'maximum-chunk', [1, 2, 100], 'INSERT: crosses two boundaries');

    foreach (['sqlite.claim', 'sqlite.claimed_dequeue'] as $scenario) {
        $add($scenario, 'median_db_transactions', 'maximum-per', [1, 0, 1], 'transaction: at most one per claim');
        $add($scenario, 'median_db_queries', 'maximum-per', [4, 0, 1], 'statements: bounded claim path');
    }

    $durableWorkerScenarios = [
        'worker.execute_ack',
        'worker.event_listener_execute_ack',
        'worker.result_serialization',
        'worker.retry',
    ];
    foreach ($durableWorkerScenarios as $scenario) {
        $add($scenario, 'median_driver_roundtrips', 'exact-per', [2, 0, 1], 'dequeue + outcome: no amplification');
        $add($scenario, 'median_db_transactions', 'exact-per', [1, 0, 1], 'transaction: one claim per job');
        $add($scenario, 'median_db_queries', 'exact-per', [4, 0, 1], 'statements: claim and fenced outcome');
    }
    foreach (['worker.execute_ack', 'worker.result_serialization', 'worker.retry'] as $scenario) {
        $add($scenario, 'median_event_deliveries', 'exact-fixed', [0, 0, 1], 'listener: disabled');
    }
    $add(
        'worker.event_listener_execute_ack',
        'median_event_deliveries',
        'exact-per',
        [2, 0, 1],
        'events: claimed + completed'
    );
    $add(
        'worker.middleware_execute_ack',
        'median_driver_roundtrips',
        'maximum-per',
        [2, 0, 1],
        'dequeue + ACK: middleware adds none'
    );
    $add('worker.middleware_execute_ack', 'median_event_deliveries', 'exact-fixed', [0, 0, 1], 'listener: disabled');

    foreach (['all_miss', 'all_hit', 'mixed'] as $distribution) {
        $optimized = 'sqlite.reconcile.optimized.' . $distribution;
        $fallback = 'sqlite.reconcile.fallback.' . $distribution;
        foreach ([$optimized, $fallback] as $scenario) {
            $add($scenario, 'median_db_queries', 'maximum-fixed', [0, 1, 1], 'cursor query: one bounded page');
            $add($scenario, 'median_db_transactions', 'exact-fixed', [0, 0, 1], 'read scan: no transaction');
        }
        $add($optimized, 'median_driver_roundtrips', 'exact-fixed', [0, 1, 1], 'batch reconcile: one per page');
        $add($fallback, 'median_driver_roundtrips', 'maximum-per', [3, 0, 1], 'legacy path: three per item');
    }

    $add('admin.requeue_failed', 'median_driver_roundtrips', 'maximum-per', [1, 0, 1], 'enqueue: one per job');
    $add(
        'worker.idle_maintenance',
        'median_db_queries',
        'maximum-per',
        [1, 0, 1],
        'reads: at most one per clock cycle'
    );
    $add(
        'worker.idle_maintenance',
        'median_db_transactions',
        'exact-fixed',
        [0, 0, 1],
        'read maintenance: no transaction'
    );

    $add('redis.dispatch_scheduled_single', 'median_redis_roundtrips', 'maximum-per', [1, 0, 1], 'ZADD: one per job');
    $add('redis.dispatch_scheduled_batch', 'median_redis_roundtrips', 'exact-fixed', [0, 1, 1], 'ZADD: one per batch');
    $add('redis.promote_delayed', 'median_redis_roundtrips', 'maximum-fixed', [0, 1, 1], 'Lua: one promotion');
    $add('redis.dispatch_batch', 'median_redis_commands', 'exact-fixed', [0, 1, 1], 'LPUSH: one batch command');
    $add('redis.dispatch_batch', 'median_redis_roundtrips', 'exact-fixed', [0, 1, 1], 'LPUSH: one batch roundtrip');
    $add('redis.dequeue_ack', 'median_redis_roundtrips', 'maximum-per', [2, 1, 1], 'pop + ACK Lua: plus empty probe');
    $add('redis.dequeue_ack', 'median_redis_commands', 'maximum-per', [2, 1, 1], 'pop + ACK Lua: plus empty probe');
    $add('redis.retry', 'median_redis_roundtrips', 'exact-per', [2, 0, 1], 'pop + NACK Lua: two per retry');
    $add('redis.retry', 'median_redis_commands', 'exact-per', [2, 0, 1], 'pop + NACK Lua: two per retry');
    $add(
        'redis.repair_unscored',
        'median_redis_roundtrips',
        'maximum-fixed',
        [0, 4, 1],
        'pipeline + Lua: bounded phases'
    );
    $add('redis.repair_unscored', 'median_redis_commands', 'maximum-per', [2, 2, 1], 'score repair: linear commands');

    return $budgets;
}

/**
 * @param list<array<string, mixed>> $results Benchmark scenario results
 * @return array<string, array<string, mixed>>
 */
function indexScenarios(array $results): array
{
    $byName = [];
    foreach ($results as $result) {
        $name = $result['name'] ?? null;
        if (!is_string($name) || $name === '') {
            throw new UnexpectedValueException('Benchmark scenario name must be a non-empty string');
        }
        $byName[$name] = $result;
    }
    return $byName;
}

/** @param array<string, mixed> $result Scenario result */
function operationCount(array $result): int
{
    return max(1, integerMetric($result, 'median_operations'));
}

/** @param array<string, mixed> $result Scenario result */
function integerMetric(array $result, string $metric): int
{
    $value = $result[$metric] ?? null;
    if (!is_int($value) && !is_float($value)) {
        throw new UnexpectedValueException("Benchmark metric {$metric} must be numeric");
    }
    return (int) $value;
}
