# Operations

This guide assumes the durable setup in [getting started](getting-started.md).
See [configuration](configuration.md) for the full worker option reference.

## Worker and Redis settings

Configure a Redis Predis `read_write_timeout` greater than the worker
`poll_timeout`, or set it to `-1` to disable the client timeout. The library
prefix is part of every key; do not also apply an uncoordinated Predis global
prefix. Redis and Valkey service support starts at Redis 7 / Valkey 8.

The Redis driver uses `EVAL` for its non-blocking move-and-timestamp operation;
this is supported by Redis 7 and Valkey 8 with Predis 3 in either RESP mode.
Blocking `BLMOVE` necessarily has a short crash window before its timestamp write.
Maintenance scans bounded processing-list slices and stamps entries missing from
the visibility ZSET; they become recoverable after one TTL. Do not run this
driver across Redis Cluster keys unless the configured prefix/key strategy puts
the queue's pending, processing, and visibility keys in the same hash slot.
For handlers expected to exceed `stuck_job_ttl`, report progress at least every
`stuck_job_ttl / 2`, or configure a larger TTL.

Use `lock_file` to isolate worker processes. Supervisor should restart on
non-zero exits and retain stdout/stderr for diagnosis. Exit code `0` means a
normal stop or configured limit, `1` means an unhandled worker error, and `2`
means the singleton lock was unavailable.

## Scheduled workloads

A scheduled job is stored with an absolute UTC `available_at` and, on
delayed-capable drivers, a delayed notification that is promoted when due. The
worker promotes due notifications on its `promote_interval` cadence and before
every `processOne()`, up to `promote_limit` (default `100`) jobs per pass.

Redis scheduled workloads that dispatch large batches at the same instant can
outpace the default limit, which delays visibility by up to
`ceil(backlog / promote_limit) * promote_interval` per worker. To keep due
jobs visible sooner:

- Raise `promote_limit` so a full scheduled batch promotes in one pass.
- Lower `promote_interval` to reduce the worst-case promotion lag.
- Add workers to scale promotion throughput; each worker promotes independently
  on its own cadence, so promotion work is parallelized.
- Keep `promote_limit` bounded to avoid one worker monopolizing a very large
  delayed ZSET in a single pass; the Redis driver executes promotion as one
  bounded Lua roundtrip per pass regardless of the limit.

Storage claims also gate on `available_at`, so a worker whose clock is behind
the dispatcher's cannot claim a scheduled job early; a clock that is ahead can
claim up to the skew amount early. Schedule availability is absolute time, not
wall-clock offset, so keep server clocks synchronized (for example with NTP)
across dispatchers and workers.

## Retention and repair

Call `JobStorageAdminInterface::pruneCompleted()` on a scheduled maintenance
job. Retention applies to terminal records whose completion timestamp is older
than the configured period; keep enough history for incident investigation.
`QueueReconciler` performs bounded source-of-truth repair. Persist
`ReconcileResult::$nextCursor` when invoking it from cron; workers keep this
cursor in memory. Pending jobs are scanned in ascending ID order and wrap after
the final page, so old records are eventually considered. The duration setting
is a soft deadline checked between bounded membership operations. Redis pending
membership uses a bounded `LPOS`; a false negative can enqueue a duplicate,
which is safe under the library's at-least-once delivery contract.

## Failure model

At-least-once delivery means external side effects must be idempotent. Monitor
pending, delayed, and processing counts, stale recovery, failed jobs, and
reconciliation errors. A storage write is authoritative; a notifier cleanup
failure indicates an inconsistency to repair and must not be treated as a
storage rollback.

## Upgrade safety

The v1.4 lease migration must be applied before using lease-based custom
storage implementations. Preserve existing Redis keys and add new keys only
with an upgrade-safe rollout. Validate configuration and run the compatibility
smoke test after deploying a new library version.

## Release and deployment checks

Run `composer validate --strict`, `composer audit`, `composer check`, and the
coverage command from a clean checkout. CI also resolves lowest supported
dependencies and tests PHP 8.2–8.5 against Redis 7, Valkey 8, MySQL 8,
PostgreSQL 15, and SQLite. Install smoke tests should include database-only
usage without Predis and existing v1.4 schema/key deployments.
Keep the compatibility smoke tests and raise coverage through focused tests;
do not lower quality gates to accommodate unrelated changes.
