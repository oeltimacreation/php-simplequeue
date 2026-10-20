# Performance benchmarks

The performance harness measures queue-library work rather than application handler
work. Each scenario performs one warmup by default and reports all measured
samples plus medians and ranges for elapsed time, throughput, CPU time, and peak
incremental memory. It also reports retained memory, PDO statement and
transaction counts, queue-driver operations, Redis command and network-roundtrip
counts, Redis script wire bytes, and lifecycle-event deliveries.

Run the reproducible local SQLite and in-memory suite:

```bash
composer benchmark -- --jobs=1000 --iterations=5 --warmup=1 --idle-cycles=500
```

Add real Redis or Valkey measurements by starting an isolated instance and
setting `REDIS_HOST` and `REDIS_PORT`. The harness uses unique key prefixes and
removes them after each sample.

```bash
REDIS_HOST=127.0.0.1 REDIS_PORT=6379 composer benchmark -- --jobs=1000
```

Results are JSON so benchmark invocations can be archived and compared without
parsing terminal formatting. The environment section records PHP, kernel,
SQLite, Redis/Valkey, and measurement details. Setup and per-sample cleanup run
outside the timed section. The report includes generic `driver_roundtrips` for
instrumented non-Redis drivers alongside the existing PDO and Redis counters.
Compare medians and ranges across multiple samples; individual
microbenchmark timings are affected by CPU scheduling, allocator state, PHP
version, database server latency, and Redis/Valkey transport latency.

The scenarios cover repeated single dispatch, one batch dispatch, scheduled
single and batch dispatch, storage claims, worker execution and
acknowledgement, listener-enabled worker execution, middleware-enabled worker
execution, worker retry scheduling, failed-job re-queue, bounded
reconciliation, deterministic idle-worker maintenance, an idle-worker
CPU/memory check, Redis batch enqueue, Redis scheduled dispatch, a large-backlog
(10,000 job) delayed promotion, Redis dequeue/ACK, Redis retry, and repair of
blocking-dequeue notifications that are missing processing scores.

Each sample records process CPU seconds when `getrusage()` is available and
estimated Redis wire payload bytes for `EVAL`/`EVALSHA` script traffic, so CPU
cost and script transport can be compared directly against a baseline run.

The runner keeps one stable JSON shape for all scenarios. Shared counter
normalization supplies the database, driver, Redis, and event fields, while
`benchmarks/operation-count-checks.php` continues to fail a run when a hot-loop
statement, roundtrip, or event-delivery budget changes.

SQL reports retain the historical `db_queries` prepare/exec counter for existing
budgets and additionally report `db_prepares`, `db_executions` (including direct
queries), and `db_fetched_json_bytes`. Reusing a prepared statement therefore
never hides its executions. Transaction-control calls are counted separately.

The candidate workloads also measure atomic idempotent batch creation followed
by an identical submission, claims/recovery/compact reads with 32-byte and 1 MiB
payload values, sampled Redis statistics, positive-timeout blocking dequeue,
and real Redis all-hit/all-miss/mixed reconciliation against a 10,000-entry
backlog. Payload scenarios cap the number of rows at ten to keep the large-value
profile bounded. Blocking dequeue seeds ready work; it measures the positive
timeout transport path without including an empty-queue wait. Reconciliation
pages grow through 10/100/1,000/10,000 in the profile command.

Budgets assert actual claim executions, zero fetched JSON for recovery/compact
queries, chunk-based idempotent I/O, one statistics pipeline, and one atomic
reconciliation roundtrip. They do not impose shared-runner timing thresholds.
