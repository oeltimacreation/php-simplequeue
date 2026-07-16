# Upgrading

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
