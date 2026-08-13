# Performance profile

## v1.9 Stage 4–5 validation profile

Captured 2026-08-13 before and after the v1.9 Stage 1–3 implementation using
PHP 8.4.24 on Linux x86-64, SQLite, and an isolated Redis/Valkey 7.2 service.
Each result is the median of five measured samples after one warmup:

```bash
REDIS_HOST=127.0.0.1 REDIS_PORT=6379 composer benchmark -- \
  --jobs=1000 --iterations=5 --warmup=1 --idle-cycles=500
```

The baseline was run from the `v1.8.0` tag and the after profile from the v1.9
implementation branch after Stages 1–3. Timings are local microbenchmark
measurements and should be compared as ranges, not as release-level capacity
guarantees.

### Existing paths — before and after

| Scenario | v1.8.0 median | v1.9 after median | v1.9 throughput | Operation-count result |
|---|---:|---:|---:|---|
| In-memory batch dispatch, 1,000 jobs | 4.884 ms | 5.006 ms | 199,749 jobs/s | unchanged |
| SQLite single dispatch, 1,000 jobs | 18.027 ms | 17.629 ms | 56,724 jobs/s | 1,000 statements |
| SQLite batch dispatch, 1,000 jobs | 5.091 ms | 4.998 ms | 200,082 jobs/s | 1 statement |
| SQLite claim, 1,000 jobs | 74.749 ms | 75.937 ms | 13,169 jobs/s | 3,000 statements / 1,000 transactions |
| Worker execute/ACK, 1,000 jobs | 112.301 ms | 111.479 ms | 8,970 jobs/s | 4,000 statements / 1,000 transactions |
| Worker retry, 1,000 jobs | 192.620 ms | 189.906 ms | 5,266 jobs/s | 4,000 statements / 1,000 transactions |
| Redis dequeue/ACK, 100 jobs | 11.204 ms | 7.815 ms | 12,796 jobs/s | 201 roundtrips |
| Redis retry, 100 jobs | 11.659 ms | 9.795 ms | 10,209 jobs/s | 200 roundtrips |
| Redis unscored repair, 100 jobs | 4.568 ms | 2.604 ms | 38,397 jobs/s | 4 roundtrips |

The existing database statement/transaction counts and Redis command/roundtrip
bounds are unchanged. The measured throughput changes are within the expected
run-to-run variance for this local harness; the Redis rows retain the previously
measured improvements from the Stage 1–3 baseline. No middleware or
administration work is inserted into the no-middleware worker path.

### New v1.9 paths

| Scenario | Median time | Throughput | Driver roundtrips |
|---|---:|---:|---:|
| Middleware worker execute/ACK, 1,000 jobs | 31.539 ms | 31,706 jobs/s | 2,000 (2/job) |
| Failed-job re-queue, 1,000 jobs | 14.481 ms | 69,058 jobs/s | 1,000 (1/job) |

The harness now records `driver_roundtrips` for non-Redis instrumented drivers.
`benchmarks/operation-count-checks.php` fails a benchmark run if middleware
exceeds the normal dequeue-plus-ACK budget or failed-job re-queue emits more than
one notification per job. The deterministic soak suite additionally exercises
300 middleware-enabled jobs across worker recycling and a 150-job failed backlog
through paginated purge.

## v1.7 Stage 2 profile

Captured 2026-08-01 against the v1.6 report below. This profile measures the
Stage 2 performance program: `EVALSHA` with NOSCRIPT fallback (P1), the
expanded benchmark scenarios (P2), the hot-path hydration allocation review
(P3), and the benchmark-gated pipelined scheduled batch enqueue (P4).

### Method

PHP 8.4.24, SQLite 3.45.1, and an isolated Valkey 7.2.13 on Linux x86-64
(Intel Core i5-13400). Each result is the median of five measured samples after
one warmup:

```bash
REDIS_HOST=127.0.0.1 REDIS_PORT=6379 composer benchmark -- \
  --jobs=1000 --iterations=5 --warmup=1 --idle-cycles=500
```

The harness now records `redis_wire_bytes` (the payload bytes of `EVAL`/`EVALSHA`
script bodies) and `cpu_seconds` (process CPU time from `getrusage()`).

### P1 — Redis Lua EVALSHA

The dequeue, delayed-promotion, and stale-recovery scripts now run through
`RedisScriptRunner`, which sends `EVALSHA` (40-byte SHA1 digest) and falls back
to `EVAL` only when Redis reports `NOSCRIPT` (for example after a script-cache
flush). Roundtrip counts and behavior are unchanged; only the wire payload
changes. Script bodies are 147, 350, and 525 bytes respectively.

| Scenario | EVAL wire bytes | EVALSHA wire bytes | Saved |
|---|---:|---:|---:|
| Dequeue/ACK, 100 jobs + empty probe (101 script calls) | 14,847 | 4,040 | 10,807 (72.8%) |
| Retry, 100 jobs (100 script calls) | 14,700 | 4,000 | 10,700 (72.8%) |
| Delayed promotion, 10,000 due jobs (1 call) | 350 | 40 | 310 (88.6%) |
| Stale-recovery repair, 100 jobs (1 call) | 525 | 40 | 485 (92.4%) |

localhost latency for the affected scenarios:

| Scenario | Before median | After median | Change |
|---|---:|---:|---:|
| Redis dequeue/ACK, 100 jobs | 10.615 ms | 8.062 ms | -24.1% |
| Redis repair unscored, 100 jobs | 2.732 ms | 2.652 ms | -2.9% |
| Redis retry, 100 jobs | 8.938 ms | 10.049 ms | variance (pipelined ACK dominates) |

The dequeue loop shows the clearest win; the retry sample is dominated by the
two pipelined ACK/nack commands and its ranges overlap.

### P2 — Expanded benchmark scenarios

New scenarios with this profile (first measurement, no prior baseline):

| Scenario | Median time | Throughput | Notable counters |
|---|---:|---:|---|
| In-memory scheduled batch dispatch, 1,000 jobs | 4.537 ms | 220,411 jobs/s | — |
| SQLite scheduled single dispatch, 1,000 jobs | 19.994 ms | 50,014 jobs/s | 1,000 PDO statements |
| SQLite scheduled batch dispatch, 1,000 jobs | 5.733 ms | 174,434 jobs/s | 1 PDO statement |
| Redis scheduled single dispatch, 100 jobs | 3.273 ms | 30,549 jobs/s | 100 roundtrips |
| Redis scheduled batch dispatch, 100 jobs | 0.764 ms | 130,963 jobs/s | 1 roundtrip |
| Redis delayed promotion, 10,000 due jobs | 3.691 ms | 2,709,455 jobs/s | 1 roundtrip, 40 wire bytes |
| Idle worker CPU/memory, 500 cycles | 1.015 ms | 492,376 cycles/s | 0.00101 CPU s, 5,720 B peak |

The 10,000-job promotion exercises the bounded promotion Lua script. Redis's
Lua argument limit (~8,000) made a single `unpack()` fail at this size, so the
promote script now chunks its `LPUSH`/`ZREM` into 1,000-item slices within the
same script. Behavior and roundtrip count are unchanged for the previous
100-job limit.

### P3 — Hot-path allocation review

The claim → hydrate → execute → complete path was profiled with Xdebug. Exactly
one `JobData::fromRaw()` hydration happens per claim (no redundant hydration),
and JSON encode/decode happens once per persisted payload as required. The
measured allocation hotspot was `JobDataHydrator::hydrate()`, which combined
`array_filter()` and `array_replace()` into two intermediate arrays per call.

Replacing that with a single merge loop removed the `array_filter` closure and
filtered-array allocations. Xdebug cumulative allocation for 105,000
hydrations fell from ~637 MB (`array_filter` 497 MB + `array_replace` 140 MB +
hydrate) to ~275 MB, a ~57% reduction; the incremental peak in the 100,000
hydration micro-profile fell from 2,896 to 2,200 bytes. The full-suite
scenarios show a neutral-to-positive throughput change (worker execute/ACK
113.090 → 111.041 ms, worker retry 191.765 → 186.234 ms) with unchanged peak
incremental memory because hydration arrays are transient.

### P4 — Pipelined scheduled batch enqueue

The P2 measurement justified the optional method: scheduled batch dispatch
with Redis cost N ZADD roundtrips, which dominates on real networks. The
benchmark-gated `SupportsDelayedJobs::enqueueDelayedBatch()` sends one ZADD
with all members (1 command, 1 roundtrip), and `JobDispatcher::dispatchBatch()`
uses it. Drivers that skip the method still work through a per-job fallback.

| Scenario (100 jobs) | Before | After | Change |
|---|---:|---:|---:|
| Redis scheduled batch dispatch | 3.117 ms, 100 roundtrips, 100 commands | 0.764 ms, 1 roundtrip, 1 command | -75.5%, -99% roundtrips |

### Operation counts

Unchanged from v1.6 except the intended reductions: Redis dequeue/ACK and
retry keep the same command and roundtrip counts (script transport switched
from `EVAL` to `EVALSHA`), scheduled batch dispatch drops from N to 1 ZADD, and
the 10,000-job promotion stays one roundtrip. PDO statement counts are
unchanged across dispatch, claim, execute, and retry.

## v1.7 Stage 3 — no hot-loop amplification

Stage 3 hardened the scheduled-dispatch failure surface without changing the
hot-loop budget. The counter invariants below are now **asserted** by the
harness (`benchmarks/operation-count-checks.php`), so a regression that adds a
roundtrip or a storage statement to the worker iteration path fails the run
instead of silently changing the published numbers:

| Invariant | Asserted bound |
|---|---|
| Scheduled single dispatch → one `enqueueDelayed` roundtrip per job | `redis_roundtrips <= operations` |
| Scheduled batch dispatch → one delayed-notification roundtrip | `redis_roundtrips <= 1` |
| Delayed promotion → one bounded Lua roundtrip per pass | `redis_roundtrips <= 1` |
| Dequeue/ACK → dequeue, pipelined ACK, and the empty probe | `redis_roundtrips <= 2 * operations + 1` |
| Database claim → one transaction and bounded statements per claim | `db_transactions <= operations` and `db_queries <= 4 * operations` |

The promotion limit is now tunable through the worker `promote_limit` option
(default `100`), so scheduled backlogs can be promoted in fewer passes while
still executing as one bounded Lua roundtrip per pass. Reconciliation parses
stored `available_at` timestamps as UTC, so availability decisions are
independent of the host timezone.

## v1.6 performance profile

This report records the performance profile captured on 2026-07-26. The benchmark
harness is [`benchmarks/run.php`](../benchmarks/run.php); it emits JSON with all
samples so results can be compared without parsing display-oriented output.

### Method

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

### Before and after

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

### Operation counts

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

### PDO review

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

### Redis and idle-worker review

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
