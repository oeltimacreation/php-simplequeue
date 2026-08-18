<?php

declare(strict_types=1);

/**
 * Stage 3 S4 — assert the hot-loop operation counters.
 *
 * These checks turn the "no hot-loop amplification" invariants into hard
 * failures instead of observations:
 *
 * - scheduled single dispatch is one driver roundtrip per job;
 * - scheduled batch dispatch is a single delayed-notification roundtrip;
 * - delayed promotion stays one bounded Lua roundtrip;
 * - dequeue/ACK stays two roundtrips per job plus the empty probe;
 * - the database claim path keeps one transaction and bounded statements per
 *   claim;
 * - worker execution keeps two queue operations per job, and the normal path
 *   does not deliver lifecycle events.
 *
 * Redis checks run only when Redis scenarios are present, so a local-only run
 * still validates the SQLite claim path.
 *
 * @param list<array<string, mixed>> $results Benchmark scenario results
 */
function assertHotLoopCounters(array $results): void
{
    $byName = indexScenarios($results);
    assertDatabaseDispatchPaths($byName);
    assertDatabaseClaimPath($byName);
    assertDatabaseWorkerPaths($byName);
    assertReconciliationPath($byName);
    assertWorkerPaths($byName);
    assertFailedJobRequeuePath($byName);
    if (!isset($byName['redis.dispatch_scheduled_single'])) {
        return;
    }
    assertScheduledSingleDispatch($byName);
    assertScheduledBatchDispatch($byName);
    assertDelayedPromotion($byName);
    assertDequeueAck($byName);
    assertRedisBatchDispatch($byName);
    assertRedisRetry($byName);
    assertRedisRepair($byName);
}

/**
 * Database dispatch keeps one statement per single job and one batch statement.
 *
 * @param array<string, array<string, mixed>> $byName Indexed benchmark results
 */
function assertDatabaseDispatchPaths(array $byName): void
{
    foreach (['sqlite.dispatch_single', 'sqlite.dispatch_scheduled_single'] as $name) {
        $single = requireScenario($byName, $name);
        assertMetricEquals(
            $single,
            'median_db_queries',
            operationCount($single),
            "{$name} statement count changed"
        );
    }

    foreach (['sqlite.dispatch_batch', 'sqlite.dispatch_scheduled_batch'] as $name) {
        $batch = requireScenario($byName, $name);
        assertMetricEquals(
            $batch,
            'median_db_queries',
            1,
            "{$name} is no longer one database statement"
        );
    }
}

/**
 * Worker paths keep queue operations bounded and only deliver configured events.
 *
 * @param array<string, array<string, mixed>> $byName Indexed benchmark results
 */
function assertWorkerPaths(array $byName): void
{
    assertWorkerPath($byName, 'worker.execute_ack', 0, true);
    assertWorkerPath($byName, 'worker.middleware_execute_ack', 0, false);
    assertWorkerPath($byName, 'worker.event_listener_execute_ack', 2, true);
    assertWorkerPath($byName, 'worker.retry', 0, true);
}

/**
 * Assert queue and event metrics for one worker scenario.
 *
 * @param array<string, array<string, mixed>> $byName Indexed benchmark results
 * @param string $name Worker scenario name
 * @param int $eventMultiplier Expected event deliveries per operation
 * @param bool $exactQueueOperations Whether queue operations must equal the bound
 */
function assertWorkerPath(array $byName, string $name, int $eventMultiplier, bool $exactQueueOperations): void
{
    $worker = requireScenario($byName, $name);
    $operations = operationCount($worker);
    $queueBound = 2 * $operations;

    if ($exactQueueOperations) {
        assertMetricEquals($worker, 'median_driver_roundtrips', $queueBound, "{$name} queue operations changed");
    } else {
        assertMetricAtMost($worker, 'median_driver_roundtrips', $queueBound, "{$name} adds driver roundtrips");
    }

    assertMetricEquals(
        $worker,
        'median_event_deliveries',
        $eventMultiplier * $operations,
        "{$name} event delivery count changed"
    );
}

/**
 * Administrative failed-job re-queue emits at most one notification per job.
 *
 * @param array<string, array<string, mixed>> $byName Indexed benchmark results
 */
function assertFailedJobRequeuePath(array $byName): void
{
    $requeue = requireScenario($byName, 'admin.requeue_failed');
    assertMetricAtMost(
        $requeue,
        'median_driver_roundtrips',
        operationCount($requeue),
        'admin.requeue_failed exceeds one notification per job'
    );
}

/**
 * Index benchmark results by scenario name.
 *
 * @param list<array<string, mixed>> $results Benchmark scenario results
 * @return array<string, array<string, mixed>>
 */
function indexScenarios(array $results): array
{
    $byName = [];
    foreach ($results as $result) {
        $byName[$result['name']] = $result;
    }
    return $byName;
}

/**
 * Return a scenario result by name or fail the run.
 *
 * @param array<string, array<string, mixed>> $byName Indexed scenario results
 * @param string $name Scenario name
 * @return array<string, mixed>
 */
function requireScenario(array $byName, string $name): array
{
    if (!isset($byName[$name])) {
        throw new RuntimeException("Missing benchmark scenario {$name} for operation-counter assertion");
    }
    return $byName[$name];
}

/**
 * @param array<string, mixed> $result Scenario result
 */
function operationCount(array $result): int
{
    return max(1, (int) $result['median_operations']);
}

/**
 * Assert that a measured metric has the expected value.
 *
 * @param array<string, mixed> $result Scenario result
 * @param string $metric Metric name
 * @param int $expected Expected integer value
 * @param string $message Failure message
 */
function assertMetricEquals(array $result, string $metric, int $expected, string $message): void
{
    if ((int) $result[$metric] !== $expected) {
        throw new RuntimeException($message);
    }
}

/**
 * Assert that a measured metric does not exceed its bound.
 *
 * @param array<string, mixed> $result Scenario result
 * @param string $metric Metric name
 * @param int $maximum Maximum allowed integer value
 * @param string $message Failure message
 */
function assertMetricAtMost(array $result, string $metric, int $maximum, string $message): void
{
    if ((int) $result[$metric] > $maximum) {
        throw new RuntimeException($message);
    }
}

/**
 * Database claim path is unchanged: one transaction per claim, bounded statements.
 *
 * @param array<string, array<string, mixed>> $byName Indexed scenario results
 */
function assertDatabaseClaimPath(array $byName): void
{
    $claim = requireScenario($byName, 'sqlite.claim');
    $operations = operationCount($claim);
    assertMetricAtMost(
        $claim,
        'median_db_transactions',
        $operations,
        'sqlite.claim transaction count exceeds one per claim'
    );
    assertMetricAtMost(
        $claim,
        'median_db_queries',
        4 * $operations,
        'sqlite.claim statement count exceeds four per claim'
    );
}

/**
 * Worker completion and retry paths retain one transaction and four statements per job.
 *
 * @param array<string, array<string, mixed>> $byName Indexed benchmark results
 */
function assertDatabaseWorkerPaths(array $byName): void
{
    foreach (['worker.execute_ack', 'worker.event_listener_execute_ack', 'worker.retry'] as $name) {
        $worker = requireScenario($byName, $name);
        $operations = operationCount($worker);
        assertMetricEquals($worker, 'median_db_transactions', $operations, "{$name} transaction count changed");
        assertMetricEquals($worker, 'median_db_queries', 4 * $operations, "{$name} statement count changed");
    }
}

/**
 * The benchmark reconciliation uses one bounded page and therefore one query.
 *
 * @param array<string, array<string, mixed>> $byName Indexed benchmark results
 */
function assertReconciliationPath(array $byName): void
{
    $reconcile = requireScenario($byName, 'sqlite.reconcile');
    assertMetricAtMost($reconcile, 'median_db_queries', 1, 'sqlite.reconcile query count exceeded one page');
    assertMetricEquals($reconcile, 'median_db_transactions', 0, 'sqlite.reconcile unexpectedly opened a transaction');
}

/**
 * Scheduled single dispatch is one enqueueDelayed roundtrip per job.
 *
 * @param array<string, array<string, mixed>> $byName Indexed scenario results
 */
function assertScheduledSingleDispatch(array $byName): void
{
    $single = requireScenario($byName, 'redis.dispatch_scheduled_single');
    assertMetricAtMost(
        $single,
        'median_redis_roundtrips',
        operationCount($single),
        'redis.dispatch_scheduled_single exceeds one roundtrip per job'
    );
}

/**
 * Scheduled batch dispatch sends one delayed-notification roundtrip.
 *
 * @param array<string, array<string, mixed>> $byName Indexed scenario results
 */
function assertScheduledBatchDispatch(array $byName): void
{
    $batch = requireScenario($byName, 'redis.dispatch_scheduled_batch');
    assertMetricAtMost($batch, 'median_redis_roundtrips', 1, 'redis.dispatch_scheduled_batch exceeds one roundtrip');
}

/**
 * Delayed promotion stays one bounded Lua roundtrip.
 *
 * @param array<string, array<string, mixed>> $byName Indexed scenario results
 */
function assertDelayedPromotion(array $byName): void
{
    $promote = requireScenario($byName, 'redis.promote_delayed');
    assertMetricAtMost(
        $promote,
        'median_redis_roundtrips',
        1,
        'redis.promote_delayed exceeds one bounded Lua roundtrip'
    );
}

/**
 * Dequeue/ACK stays bounded: dequeue, pipelined ACK, and the empty probe.
 *
 * @param array<string, array<string, mixed>> $byName Indexed scenario results
 */
function assertDequeueAck(array $byName): void
{
    $dequeueAck = requireScenario($byName, 'redis.dequeue_ack');
    $operations = operationCount($dequeueAck);
    assertMetricAtMost(
        $dequeueAck,
        'median_redis_roundtrips',
        2 * $operations + 1,
        'redis.dequeue_ack roundtrips amplified per job'
    );
    assertMetricAtMost(
        $dequeueAck,
        'median_redis_commands',
        3 * $operations + 1,
        'redis.dequeue_ack command count amplified per job'
    );
}

/**
 * Redis batch enqueue is one command and one roundtrip.
 *
 * @param array<string, array<string, mixed>> $byName Indexed benchmark results
 */
function assertRedisBatchDispatch(array $byName): void
{
    $batch = requireScenario($byName, 'redis.dispatch_batch');
    assertMetricEquals($batch, 'median_redis_commands', 1, 'redis.dispatch_batch is no longer a single command');
    assertMetricEquals($batch, 'median_redis_roundtrips', 1, 'redis.dispatch_batch is no longer one roundtrip');
}

/**
 * Redis retry keeps one dequeue and one pipelined NACK roundtrip per job.
 *
 * @param array<string, array<string, mixed>> $byName Indexed benchmark results
 */
function assertRedisRetry(array $byName): void
{
    $retry = requireScenario($byName, 'redis.retry');
    $operations = operationCount($retry);
    assertMetricEquals($retry, 'median_redis_roundtrips', 2 * $operations, 'redis.retry roundtrip count changed');
    assertMetricEquals($retry, 'median_redis_commands', 4 * $operations, 'redis.retry command count changed');
}

/**
 * Redis processing-score repair remains bounded even though it scans one job at a time.
 *
 * @param array<string, array<string, mixed>> $byName Indexed benchmark results
 */
function assertRedisRepair(array $byName): void
{
    $repair = requireScenario($byName, 'redis.repair_unscored');
    $operations = operationCount($repair);
    assertMetricAtMost(
        $repair,
        'median_redis_roundtrips',
        4,
        'redis.repair_unscored roundtrips exceeded its bound'
    );
    assertMetricAtMost(
        $repair,
        'median_redis_commands',
        2 * $operations + 2,
        'redis.repair_unscored command count amplified'
    );
}
