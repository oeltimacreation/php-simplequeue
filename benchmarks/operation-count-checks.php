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
    assertNormalWorkerPath($byName);
    assertMiddlewarePath($byName);
    assertListenerWorkerPath($byName);
    assertRetryWorkerPath($byName);
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
        if ((int) $single['median_db_queries'] !== operationCount($single)) {
            throw new RuntimeException("{$name} statement count changed");
        }
    }

    foreach (['sqlite.dispatch_batch', 'sqlite.dispatch_scheduled_batch'] as $name) {
        $batch = requireScenario($byName, $name);
        if ((int) $batch['median_db_queries'] !== 1) {
            throw new RuntimeException("{$name} is no longer one database statement");
        }
    }
}

/**
 * The normal worker path is dequeue plus ACK, without event delivery work.
 *
 * @param array<string, array<string, mixed>> $byName Indexed benchmark results
 */
function assertNormalWorkerPath(array $byName): void
{
    $worker = requireScenario($byName, 'worker.execute_ack');
    $operations = operationCount($worker);
    if ((int) $worker['median_driver_roundtrips'] !== 2 * $operations) {
        throw new RuntimeException('worker.execute_ack queue operations changed');
    }
    if ((int) $worker['median_event_deliveries'] !== 0) {
        throw new RuntimeException('worker.execute_ack delivered unconfigured events');
    }
}

/**
 * Middleware is a worker-layer wrapper and must not add queue operations.
 *
 * @param array<string, array<string, mixed>> $byName Indexed benchmark results
 */
function assertMiddlewarePath(array $byName): void
{
    $middleware = requireScenario($byName, 'worker.middleware_execute_ack');
    $operations = operationCount($middleware);
    if ((int) $middleware['median_driver_roundtrips'] > 2 * $operations) {
        throw new RuntimeException('worker.middleware_execute_ack adds driver roundtrips');
    }
    if ((int) $middleware['median_event_deliveries'] !== 0) {
        throw new RuntimeException('worker.middleware_execute_ack delivered unconfigured events');
    }
}

/**
 * A configured listener receives the claimed and completed event for each job.
 *
 * @param array<string, array<string, mixed>> $byName Indexed benchmark results
 */
function assertListenerWorkerPath(array $byName): void
{
    $worker = requireScenario($byName, 'worker.event_listener_execute_ack');
    $operations = operationCount($worker);
    if ((int) $worker['median_driver_roundtrips'] !== 2 * $operations) {
        throw new RuntimeException('worker.event_listener_execute_ack queue operations changed');
    }
    if ((int) $worker['median_event_deliveries'] !== 2 * $operations) {
        throw new RuntimeException('worker.event_listener_execute_ack event delivery count changed');
    }
}

/**
 * Retry processing remains dequeue plus NACK and has no listener work by default.
 *
 * @param array<string, array<string, mixed>> $byName Indexed benchmark results
 */
function assertRetryWorkerPath(array $byName): void
{
    $worker = requireScenario($byName, 'worker.retry');
    $operations = operationCount($worker);
    if ((int) $worker['median_driver_roundtrips'] !== 2 * $operations) {
        throw new RuntimeException('worker.retry queue operations changed');
    }
    if ((int) $worker['median_event_deliveries'] !== 0) {
        throw new RuntimeException('worker.retry delivered unconfigured events');
    }
}

/**
 * Administrative failed-job re-queue emits at most one notification per job.
 *
 * @param array<string, array<string, mixed>> $byName Indexed benchmark results
 */
function assertFailedJobRequeuePath(array $byName): void
{
    $requeue = requireScenario($byName, 'admin.requeue_failed');
    if ((int) $requeue['median_driver_roundtrips'] > operationCount($requeue)) {
        throw new RuntimeException('admin.requeue_failed exceeds one notification per job');
    }
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
 * Database claim path is unchanged: one transaction per claim, bounded statements.
 *
 * @param array<string, array<string, mixed>> $byName Indexed scenario results
 */
function assertDatabaseClaimPath(array $byName): void
{
    $claim = requireScenario($byName, 'sqlite.claim');
    $operations = operationCount($claim);
    if ((int) $claim['median_db_transactions'] > $operations) {
        throw new RuntimeException('sqlite.claim transaction count exceeds one per claim');
    }
    if ((int) $claim['median_db_queries'] > 4 * $operations) {
        throw new RuntimeException('sqlite.claim statement count exceeds four per claim');
    }
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
        if ((int) $worker['median_db_transactions'] !== $operations) {
            throw new RuntimeException("{$name} transaction count changed");
        }
        if ((int) $worker['median_db_queries'] !== 4 * $operations) {
            throw new RuntimeException("{$name} statement count changed");
        }
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
    if ((int) $reconcile['median_db_queries'] > 1) {
        throw new RuntimeException('sqlite.reconcile query count exceeded one page');
    }
    if ((int) $reconcile['median_db_transactions'] !== 0) {
        throw new RuntimeException('sqlite.reconcile unexpectedly opened a transaction');
    }
}

/**
 * Scheduled single dispatch is one enqueueDelayed roundtrip per job.
 *
 * @param array<string, array<string, mixed>> $byName Indexed scenario results
 */
function assertScheduledSingleDispatch(array $byName): void
{
    $single = requireScenario($byName, 'redis.dispatch_scheduled_single');
    if ((int) $single['median_redis_roundtrips'] > operationCount($single)) {
        throw new RuntimeException('redis.dispatch_scheduled_single exceeds one roundtrip per job');
    }
}

/**
 * Scheduled batch dispatch sends one delayed-notification roundtrip.
 *
 * @param array<string, array<string, mixed>> $byName Indexed scenario results
 */
function assertScheduledBatchDispatch(array $byName): void
{
    $batch = requireScenario($byName, 'redis.dispatch_scheduled_batch');
    if ((int) $batch['median_redis_roundtrips'] > 1) {
        throw new RuntimeException('redis.dispatch_scheduled_batch exceeds one roundtrip');
    }
}

/**
 * Delayed promotion stays one bounded Lua roundtrip.
 *
 * @param array<string, array<string, mixed>> $byName Indexed scenario results
 */
function assertDelayedPromotion(array $byName): void
{
    $promote = requireScenario($byName, 'redis.promote_delayed');
    if ((int) $promote['median_redis_roundtrips'] > 1) {
        throw new RuntimeException('redis.promote_delayed exceeds one bounded Lua roundtrip');
    }
}

/**
 * Dequeue/ACK stays bounded: dequeue, pipelined ACK, and the empty probe.
 *
 * @param array<string, array<string, mixed>> $byName Indexed scenario results
 */
function assertDequeueAck(array $byName): void
{
    $dequeueAck = requireScenario($byName, 'redis.dequeue_ack');
    if ((int) $dequeueAck['median_redis_roundtrips'] > 2 * operationCount($dequeueAck) + 1) {
        throw new RuntimeException('redis.dequeue_ack roundtrips amplified per job');
    }
    if ((int) $dequeueAck['median_redis_commands'] > 3 * operationCount($dequeueAck) + 1) {
        throw new RuntimeException('redis.dequeue_ack command count amplified per job');
    }
}

/**
 * Redis batch enqueue is one command and one roundtrip.
 *
 * @param array<string, array<string, mixed>> $byName Indexed benchmark results
 */
function assertRedisBatchDispatch(array $byName): void
{
    $batch = requireScenario($byName, 'redis.dispatch_batch');
    if ((int) $batch['median_redis_commands'] !== 1 || (int) $batch['median_redis_roundtrips'] !== 1) {
        throw new RuntimeException('redis.dispatch_batch is no longer a single command');
    }
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
    if ((int) $retry['median_redis_roundtrips'] !== 2 * $operations) {
        throw new RuntimeException('redis.retry roundtrip count changed');
    }
    if ((int) $retry['median_redis_commands'] !== 4 * $operations) {
        throw new RuntimeException('redis.retry command count changed');
    }
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
    if ((int) $repair['median_redis_roundtrips'] > 4) {
        throw new RuntimeException('redis.repair_unscored roundtrips exceeded its bound');
    }
    if ((int) $repair['median_redis_commands'] > 2 * $operations + 2) {
        throw new RuntimeException('redis.repair_unscored command count amplified');
    }
}
