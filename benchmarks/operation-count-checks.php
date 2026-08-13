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
 *   claim.
 *
 * Redis checks run only when Redis scenarios are present, so a local-only run
 * still validates the SQLite claim path.
 *
 * @param list<array<string, mixed>> $results Benchmark scenario results
 */
function assertHotLoopCounters(array $results): void
{
    $byName = indexScenarios($results);
    assertDatabaseClaimPath($byName);
    assertMiddlewarePath($byName);
    assertFailedJobRequeuePath($byName);
    if (!isset($byName['redis.dispatch_scheduled_single'])) {
        return;
    }
    assertScheduledSingleDispatch($byName);
    assertScheduledBatchDispatch($byName);
    assertDelayedPromotion($byName);
    assertDequeueAck($byName);
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
}
