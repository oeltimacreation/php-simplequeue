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
    $byName = [];
    foreach ($results as $result) {
        $byName[$result['name']] = $result;
    }
    $require = static function (string $name) use ($byName): array {
        if (!isset($byName[$name])) {
            throw new RuntimeException("Missing benchmark scenario {$name} for operation-counter assertion");
        }
        return $byName[$name];
    };
    $operations = static fn (array $result): int => max(1, (int) $result['median_operations']);

    // Database claim path is unchanged: one transaction per claim, bounded statements.
    $claim = $require('sqlite.claim');
    if ((int) $claim['median_db_transactions'] > $operations($claim)) {
        throw new RuntimeException('sqlite.claim transaction count exceeds one per claim');
    }
    if ((int) $claim['median_db_queries'] > 4 * $operations($claim)) {
        throw new RuntimeException('sqlite.claim statement count exceeds four per claim');
    }

    if (!isset($byName['redis.dispatch_scheduled_single'])) {
        return;
    }

    // Scheduled single dispatch is one enqueueDelayed roundtrip per job.
    $single = $require('redis.dispatch_scheduled_single');
    if ((int) $single['median_redis_roundtrips'] > $operations($single)) {
        throw new RuntimeException('redis.dispatch_scheduled_single exceeds one roundtrip per job');
    }

    // Scheduled batch dispatch sends one delayed-notification roundtrip.
    $batch = $require('redis.dispatch_scheduled_batch');
    if ((int) $batch['median_redis_roundtrips'] > 1) {
        throw new RuntimeException('redis.dispatch_scheduled_batch exceeds one roundtrip');
    }

    // Delayed promotion stays one bounded Lua roundtrip.
    $promote = $require('redis.promote_delayed');
    if ((int) $promote['median_redis_roundtrips'] > 1) {
        throw new RuntimeException('redis.promote_delayed exceeds one bounded Lua roundtrip');
    }

    // Dequeue/ACK stays bounded: dequeue, pipelined ACK, and the empty probe.
    $dequeueAck = $require('redis.dequeue_ack');
    if ((int) $dequeueAck['median_redis_roundtrips'] > 2 * $operations($dequeueAck) + 1) {
        throw new RuntimeException('redis.dequeue_ack roundtrips amplified per job');
    }
}
