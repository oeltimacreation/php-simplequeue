# Performance benchmarks

The performance harness measures queue-library work rather than application handler
work. Each scenario performs one warmup by default and reports all five measured
samples plus medians, peak incremental memory, retained memory, PDO statement
and transaction counts, and Redis command and network-roundtrip counts.

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
parsing terminal formatting. The report includes generic `driver_roundtrips` for
instrumented non-Redis drivers alongside the existing PDO and Redis counters.
Compare medians across multiple samples; individual
microbenchmark timings are affected by CPU scheduling, allocator state, PHP
version, database server latency, and Redis/Valkey transport latency.

The scenarios cover repeated single dispatch, one batch dispatch, scheduled
single and batch dispatch, storage claims, worker execution and
acknowledgement, worker retry scheduling, bounded reconciliation,
deterministic idle-worker maintenance, an idle-worker CPU/memory check,
Redis batch enqueue, Redis scheduled dispatch, a large-backlog (10,000 job)
delayed promotion, Redis dequeue/ACK, Redis retry, and repair of
blocking-dequeue notifications that are missing processing scores.

Each sample also records process CPU seconds for scenarios that provide it and
estimated Redis wire payload bytes for `EVAL`/`EVALSHA` script traffic, so the
`EVALSHA` optimization can be compared directly against a baseline run.
