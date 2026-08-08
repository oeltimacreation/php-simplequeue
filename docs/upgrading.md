# Upgrading

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

