# Backend parity map

This map records the shared domain rules used by the built-in storage and queue
implementations. Persistence and command orchestration stay backend-specific;
only rules with the same observable contract share an implementation.

## Storage rules

| Observable rule | Source of truth | Backend-specific work retained | Parity protection |
|---|---|---|---|
| Progress, retry, and bounded stale-recovery validation | `Internal\JobStorageRules` | Each storage performs its own mutation after validation | Exact exception messages and boundary values run against in-memory and PDO storage |
| Payload and result JSON encoding | `Internal\JobStorageRules` | PDO persists JSON; in-memory storage retains its typed row | Contract tests assert the same `SerializationException` message and previous unit tests cover causes |
| Durable-row hydration | `Internal\JobDataHydrator::hydrateStrict()` | PDO returns scalar strings; in-memory retains typed fields | Missing, malformed, out-of-range, ownership, and terminal-nullability cases fail consistently |
| Retry and retention timestamps | `Internal\JobStorageRules::timestamp()` | Each storage supplies its clock and existing date format | A frozen clock verifies retry availability and stale thresholds without changing precision |
| Retry terminality | `Internal\RetryDecision::forAttempt()` | PDO uses guarded SQL; in-memory storage mutates its row | Contract and concurrency tests assert attempt counts and the pending-to-failed boundary |
| Status and queue filtering | `Internal\JobFilter` | PDO builds bound SQL; in-memory storage evaluates rows directly | Contract tests run matching `list()` and `count()` cases against both implementations |
| Claim ownership | Immutable `ClaimedJob`, plus backend-local ownership checks | PDO fences every write in SQL; in-memory storage compares the typed row | Contract tests exercise wrong worker/token writes and stale-lease replacement |
| Terminal and retry transitions | Storage contract plus shared rules above | SQL assignments and in-memory field updates remain explicit | Contract tests compare status, timestamps, attempts, ownership clearing, and terminality |
| Atomic batch creation | Complete pre-validation plus backend transaction policy | In-memory commits one prepared array; PDO chunks at backend limits | Invalid middle/final items change no rows or IDs; exact IDs are checked across chunk boundaries |
| Uncertain PDO mutations | `IndeterminateStorageOutcomeException` | Only PDO has connection/commit ambiguity | The chronology harness injects acquisition, prepare, execute, result-read, rollback, and commit faults without replaying attempted writes |
| Failed-job administration | `SupportsFailedJobAdministration` and `AdminManager` | PDO uses guarded `status = 'failed'` SQL; in-memory storage mutates the private row | Failed-job contract tests compare listing, inspection, reset fields, deletion, and notification cleanup |
| Middleware execution | `JobMiddlewareInterface`, `JobContextInterface`, and `JobMiddlewareRunner` | Worker-layer pipeline is independent of storage and notification implementation | `JobMiddlewareContractTest` runs a real claim/complete cycle against in-memory, SQLite, and Redis when configured |
| Typed worker events | `WorkerEventInterface` value objects | All drivers observe the same worker lifecycle; only listener delivery remains callable-compatible | `WorkerEventContractTest` covers every event factory and stable payload, while observability tests cover listener conversion |

PDO statement preparation, bounded parameter binding, result hydration, list/count
filter construction, and the repeated `id`/`running`/`lease_token` predicate are
centralized inside `PdoJobStorage`. The assignment list remains at each call site,
so completed, failed, retry, progress, and heartbeat transitions are readable and
their single guarded command remains visible. Transaction boundaries and
database-specific claim SQL remain separate.

## Queue rules

| Observable rule | Source of truth | Backend-specific work retained | Parity protection |
|---|---|---|---|
| Redis queue key format | `RedisQueueDriver::queueKey()` | Each operation still selects its pending, processing, timestamp, or delayed key | Redis unit tests and the shared queue contract cover queue isolation and counts |
| Redis response normalization | `Internal\RedisResponseNormalizer` | Redis commands and Lua scripts remain visible in the driver | Unit tests cover malformed dequeue cleanup and integer-like script results |
| Positive job IDs | Driver-local scalar validation | Each driver retains its public error context without allocating a wrapper | Queue contract tests assert validation behavior across drivers |
| Queue lifecycle | `QueueDriverInterface` | Database polling, in-memory collections, and Redis atomic commands remain independent | One contract suite covers base lifecycle and queue isolation for every driver, with capability cases for ACK/NACK state, delay, ordering, and counts |
| Failed-job notification cleanup | `SupportsJobRemoval` | Redis and in-memory remove list/ZSET members; database polling has no separate notification structure | Administration tests assert pending, delayed, and processing notifications are removed where they exist; race tests cover re-queue versus claim |
| Lean reconciliation | `PendingNotification` and cursor/batch capabilities | Redis uses one direct Lua call; in-memory builds one bounded membership set; legacy drivers use per-item checks | All-hit, all-miss, mixed, invalid-time, bounded false-negative, and cursor-wrap cases run locally and in operation budgets |

The base queue contract runs against database polling and the in-memory driver,
and also runs against Redis when `REDIS_HOST` is configured. Batch, delayed-job,
and queue-statistics cases run only against drivers that expose those optional
capabilities. Real-service providers retain separate Redis and Valkey cases
while sharing only connection and lifecycle setup.

## Parity evidence

`tests/Support/StorageTransitionMatrix.php` is the shared state-transition
matrix for in-memory, SQLite, MySQL, and PostgreSQL. It covers create, claim,
progress/completion, retry, terminal failure, graceful release, cancellation,
failed-job reset/purge, scoped and unscoped stale recovery, and every fenced
write after lease replacement. Queue parity is protected by the contract suite
and real Redis/Valkey lane. Deterministic query/command/roundtrip budgets are
declared in `benchmarks/operation-count-checks.php`; timing is evidence, not a
shared-runner gate.
