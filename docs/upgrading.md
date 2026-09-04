# Upgrading

## To v1.11.x

v1.11 is a hardening release that corrects durable outcomes, attempt counts,
worker effect ordering, configuration, locking, reconciliation, and batch
atomicity without breaking the v1.10 public/protected API. No schema or Redis
key migration is required.

- **Attempts**: `attempts` counts failed executions; terminal failures
  (handler, serialization, stale) consume one attempt. `canRetry()` is false
  for terminal jobs, and direct retry scheduling rejects an exhausted count.
  First-attempt shutdown releases preserve `attempts 0` and prior error data.
- **PDO outcomes**: an error after a mutation may have reached the server now
  raises `IndeterminateStorageOutcomeException`; do not retry it blindly.
  Reads may retry once only with a connection factory and outside a caller
  transaction. Claims inside caller-owned transactions are rejected.
- **Storage boundaries**: built-in hydration now rejects missing/corrupt durable
  fields. Table names and schema-sized strings are validated, batches validate
  completely before mutation, and PDO chunks remain one atomic transaction.
- **Worker effects**: durable transitions persist first, events emit second,
  and ACK/NACK runs third. Notification failures after a durable transition
  escape as infrastructure; lost ownership never ACKs/NACKs.
- **Options**: numeric options require canonical strings, booleans require
  actual booleans, `memory_limit` is MiB, and array `lock_file: null` disables
  locking while typed defaults use safe per-queue locks. `processOne()`
  throws infrastructure errors instead of returning `false`.
- **Scheduling**: future dispatch requires `SupportsDelayedJobs` or
  `SupportsStorageBackedScheduling`; otherwise it throws before storage
  mutation.
- **Reconciliation/Redis**: built-ins scan lean notification projections and
  reconcile a page in one queue operation. Availability must be canonical UTC;
  `duplicates` reflects delayed membership or a bounded pending hit. Duplicate
  processing entries retain a recoverable visibility score after ACK/NACK.
- **Deprecations**: `SupportsWorkerId` and `SupportsQueueReconciliation`
  remain functional; prefer worker-aware claimed dequeue and lean/batch
  reconciliation. v2 removal candidates.

## To v1.10.x

v1.10 established the test and static-analysis baseline with reproducible
performance profiles and operation-count guardrails. No schema migration is
required from v1.9.

## To v1.9.x

v1.9 adds middleware, typed worker event value objects, and failed-job
administration without changing the existing storage or queue-driver contracts.
The release requires no database schema migration and keeps the no-middleware
worker path unchanged.

- **Middleware**: register `JobMiddlewareInterface` implementations through
  `$registry->middleware`. Middleware runs in registration order on entry and
  reverse order on exit; exceptions use the existing retry/failure path.
- **Worker events**: the worker now constructs typed readonly event objects
  internally, but `setEventListener()` still receives the same event names and
  array keys. No PSR-14 dependency is required.
- **Failed jobs**: construct `AdminManager` with the existing storage and queue
  manager to list, inspect, re-queue, or purge failed rows. Re-queue resets
  attempts and terminal metadata. Purge requires the queue driver's optional
  `SupportsJobRemoval` capability; database polling provides a safe storage-gated
  no-op.
- **Operations**: review the [operations guide](operations.md) for failed-job
  backlog monitoring and listener failure handling, then run the compatibility
  smoke test and the published benchmark command in [performance.md](performance.md).

## To v1.8.x

v1.8 is a consolidation and quality release focused on code de-duplication, precise array-shape typing (`JobDefinitionShape`, `StorageRowShape`), test suite refactoring, and documentation overhaul. It is 100% backward compatible with v1.7.x and v1.6.x, requiring zero database schema or public API changes.

- **Public APIs and Interfaces**: All public interfaces (`JobStorageInterface`, `QueueDriverInterface`, `JobHandlerInterface`) remain unchanged.
- **Claim Performance**: `PdoJobStorage` claim execution paths are unified internally without altering locking or transaction behavior (`FOR UPDATE SKIP LOCKED` / RETURNING).
- **Worker Configuration**: `WorkerOptions` object configuration is passed directly to workers without array flattening and re-parsing.
- **Array Shape Types**: `JobStorageInterface::createJobs()` explicitly documents `@phpstan-type JobDefinitionShape`.

## To v1.7.x

v1.7 introduced scheduled initial dispatch and performance optimizations while preserving backward compatibility with v1.6.x.

- **Scheduled Dispatch**: Added `JobDispatcher::dispatchAfter()`, `dispatchAt()`, and optional `$availableAt` parameter on `dispatch()`.
- **Redis Performance**: Utilizes `EVALSHA` for Redis Lua script execution with transparent fallback to `EVAL` on script cache missing.
- **Atomic Batch Scheduling**: Introduced pipelined scheduled batch enqueue (`enqueueDelayedBatch()`) for driver implementations supporting it.

## To v1.6.x

v1.6 is a consolidation release focused on code quality, type safety, performance, and stability hardening. It is 100% backward compatible with v1.5.x and requires no database schema or public API changes.

- Public APIs, interfaces, data serialization formats, and backend schemas remain unchanged from v1.5.2.
- Performance improvements for batch in-memory operations and Redis blocking dequeue score repair take effect automatically.
- Logging and event context remain informative while omitting raw stack traces from log contexts.

## To v1.5.x

v1.5 is a reliability release. It does not require an offline schema migration
from v1.4 when the lease-based v1.4 schema is already present.

- Keep the active-request-ID unique index in [database.md](database.md) for
  concurrent `dispatchIdempotent()` calls.
- Cancelled jobs now have a completion time, are retention-pruneable, and
  built-in Redis/in-memory drivers remove their notifications after storage
  cancellation.
- Invalid job inputs, worker settings, and JSON payload/result values now fail
  explicitly. `WorkerOptions` offers validated object configuration while the
  original array option remains supported.
- `QueueReconciler` runs bounded repair. Persist `ReconcileResult::$nextCursor`
  when it is scheduled outside the worker.
- After a crash during blocking Redis dequeue, visibility timestamps are
  repaired eventually. Long handlers should report progress every half TTL.

## From v1.3 to v1.4

PHP 8.2 and Predis 3 are required. Job status comparisons must use the
`JobStatus` backed enum:

```php
use Oeltima\SimpleQueue\Contract\JobStatus;

if ($job->status === JobStatus::Completed) {
    // ...
}
```

## From v1.2 to v1.3

Apply [the lease migration](../examples/migrations/1.3.0-lease-based-claims.sql).
Custom storage implementations must use `claimNextAvailable()` / `claimById()`
and accept `ClaimedJob` for fenced completion, failure, retry, progress, and
heartbeat updates. `Worker::run()` returns an exit code.
