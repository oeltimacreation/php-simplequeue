# v1.6 performance profile

This report records the performance profile captured on 2026-07-26. The benchmark
harness is [`benchmarks/run.php`](../benchmarks/run.php); it emits JSON with all
samples so results can be compared without parsing display-oriented output.

## Method

The comparison used PHP 8.4.23, SQLite 3.45.1, and an isolated Valkey 7.2.13
instance on Linux x86-64 with an Intel Core i5-13400. Each result below is the
median of five measured samples after one warmup:

```bash
REDIS_HOST=127.0.0.1 REDIS_PORT=6399 composer benchmark -- \
  --jobs=1000 --iterations=5 --warmup=1 --idle-cycles=500
```

Redis scenarios are deliberately capped at 100 jobs per sample. Incremental
peak and retained memory use PHP's non-real allocator measurements; a negative
retained value means the sample released memory that was live at its start.
Timings include library work but exclude fixture and schema setup.

## Before and after

Times are milliseconds for the complete sample. Ranges show the minimum and
maximum of the five samples.

| Scenario | Before median (range) | After median (range) | After throughput |
|---|---:|---:|---:|
| In-memory batch dispatch, 1,000 jobs | 4.045 (4.024–4.435) | 2.668 (2.658–2.736) | 374,805 jobs/s |
| SQLite repeated single dispatch, 1,000 jobs | 17.406 (17.141–17.455) | 16.971 (16.836–17.166) | 58,924 jobs/s |
| SQLite batch dispatch, 1,000 jobs | 4.544 (4.392–4.645) | 4.475 (4.402–4.643) | 223,464 jobs/s |
| SQLite claim, 1,000 jobs | 78.145 (77.787–79.053) | 77.218 (76.826–77.632) | 12,950 jobs/s |
| Worker execute/ACK, 1,000 jobs | 112.530 (111.067–114.192) | 109.509 (107.046–111.533) | 9,132 jobs/s |
| Worker retry, 1,000 jobs | 182.427 (181.219–198.153) | 178.501 (177.098–184.376) | 5,602 jobs/s |
| SQLite reconciliation, 1,000 jobs | 23.598 (23.378–24.043) | 23.209 (22.789–27.144) | 43,087 jobs/s |
| Deterministic idle maintenance | 5.057 (4.888–5.364) | 4.960 (4.908–5.499) | 506 clock steps/sample |
| Redis batch enqueue, 100 jobs | 0.123 (0.109–0.246) | 0.159 (0.146–0.172) | 629,117 jobs/s |
| Redis dequeue/ACK, 100 jobs | 8.430 (7.529–11.965) | 10.465 (8.571–15.430) | 9,555 jobs/s |
| Redis retry, 100 jobs | 10.884 (8.723–14.160) | 9.573 (8.841–12.324) | 10,446 jobs/s |
| Redis unscored-notification repair, 100 jobs | 5.038 (4.898–5.875) | 4.708 (3.693–4.761) | 21,240 jobs/s |

Only the two selected paths are claimed as improvements. The 1,000-job
in-memory batch is 34.0% faster. At 10,000 jobs, its median fell from 150.337 ms
to 28.725 ms (80.9%), and throughput rose from 66,517 to 348,131 jobs/s,
confirming that replacing repeated front insertion removed quadratic scaling.
The 10-job result moved from 0.032 ms to 0.030 ms, so the bulk operation does
not trade away representative small-batch behavior.

Redis repair retains the same server command count and crash-recovery rules but
pipelines score reads and repair writes. For 100 missing scores, network
roundtrips fell from 202 to 4 (98.0%); localhost median time fell 6.6%. The
incremental peak rose from 9,232 to 70,968 bytes because pipeline commands are
buffered, but retained memory was unchanged and work remains capped by the
existing limit of 100. Unrelated Redis timing ranges overlap and are reported
as variance, not performance changes.

## Operation counts

The counters remained stable except for the intended Redis roundtrip reduction:

| Scenario | PDO statements | Transactions | Redis commands | Redis roundtrips |
|---|---:|---:|---:|---:|
| SQLite single dispatch, 1,000 jobs | 1,000 | 0 | — | — |
| SQLite batch dispatch, 1,000 jobs | 1 | 0 | — | — |
| SQLite claim, 1,000 jobs | 3,000 | 1,000 | — | — |
| Worker execute/ACK, 1,000 jobs | 4,000 | 1,000 | — | — |
| Worker retry, 1,000 jobs | 4,000 | 1,000 | — | — |
| SQLite reconciliation, 1,000 jobs | 1 | 0 | — | — |
| Idle maintenance, 506 deterministic clock steps | 202 | 0 | — | — |
| Redis batch enqueue, 100 jobs | — | — | 1 | 1 |
| Redis dequeue/ACK, 100 jobs plus empty probe | — | — | 301 | 201 |
| Redis retry, 100 jobs | — | — | 400 | 200 |
| Redis unscored repair, 100 jobs | — | — | 202 | **202 → 4** |

## PDO review

- SQLite claims deliberately retain `BEGIN IMMEDIATE`, select, fenced update,
  refetch, and commit. MySQL retains the equivalent short transaction with
  `FOR UPDATE SKIP LOCKED`. Combining those statements would weaken portable
  locking or hydration semantics.
- PostgreSQL already uses a single atomic `UPDATE ... RETURNING` claim. Batch
  creation is one multi-row insert and uses `RETURNING id` on PostgreSQL or the
  backend's contiguous inserted-ID behavior on SQLite/MySQL.
- Prepared statements remain scoped to their operation. Caching them across
  calls was rejected because callable connection factories can reconnect and
  batch SQL has variable shape; the profile did not identify preparation as a
  distinct scaling cost.
- The documented `(queue, status, available_at, id)` index serves claim order.
  SQLite's plan uses `(queue, status)` plus the row ID for keyset reconciliation.
  A trial `(queue, status, id)` cursor index and `(queue, status, locked_at, id)`
  stale index produced cleaner maintenance plans, but maintaining them slowed
  dispatch, claim, execute, and retry medians by roughly 6–10% while the bounded
  reconciliation sample changed only 2.5%. They were therefore not added to the
  portable default schema. High-volume installations should validate optional
  maintenance indexes with MySQL/PostgreSQL `EXPLAIN` and their own write mix.

No MySQL or PostgreSQL service timings are claimed from this workstation. Their
SQL, transaction, pagination, and documented index shapes were reviewed; the
real-service performance matrix remains environment-specific.

## Redis and idle-worker review

Batch enqueue is already one `LPUSH`; ACK and retry already use one pipeline;
non-blocking dequeue, delayed promotion, and stale recovery remain bounded Lua
operations. Blocking `BLMOVE` still requires the separate score write and its
repair path because a blocking command cannot be wrapped in the dequeue Lua
script. The repair now uses one score pipeline, at most one correction pipeline,
and one timestamp read per bounded slice. An EVALSHA/Redis Functions or cluster
key redesign remains outside v1.6 scope.

The deterministic idle run completed 506 clock steps with 202 PDO statements,
a 2,312-byte maximum incremental peak, and -1,536 median retained bytes both
before and after. Promotion and queue/storage recovery remain limited to 100
items; reconciliation remains limited to a 100-row page, a 250-entry membership
scan, and one second. The worker retains only one reconciliation cursor and one
Redis repair cursor per configured queue, so this profile found no per-iteration
memory accumulation or unbounded idle work.
