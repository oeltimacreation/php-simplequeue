# Failure and recovery matrix

Storage is authoritative. Queue entries are repairable delivery notifications,
and delivery remains at least once. A fenced storage result of `false` means
ownership was lost; it never authorizes ACK, NACK, or more handler work.

## PDO outcome classes

| Boundary | What is known | Result | Safe caller action |
|---|---|---|---|
| Connection factory fails before a mutation begins | No statement was attempted | The factory is retried once; the final connection exception escapes | Retry according to application policy |
| Validation or JSON encoding fails | No database mutation began | `InvalidArgumentException` or `SerializationException` | Correct input; do not reconcile |
| Constraint/statement error with a successful owned rollback | The mutation did not commit | Original PDO exception | Correct or retry only when the error is retryable |
| Prepare/execute/commit connection error after mutation entry | The server outcome cannot be proven | `IndeterminateStorageOutcomeException` names the operation and preserves the cause | Inspect by durable identifier/request ID, then reconcile; never replay blindly |
| Idempotent create is ambiguous, then the request ID resolves one active row | One durable active job is proven | `IdempotentJobResult(created: false)` | Use the returned job ID |
| Read/result-consumption connection error outside a caller transaction | No write is replayed | One complete read retry is allowed with a connection factory | Use the result or handle the final error |
| Read error inside a caller transaction | Transaction ownership prevents reconnect | Original exception escapes, no retry | Caller decides whether to roll back |
| Claim while the caller PDO is already in a transaction | Claim atomicity cannot be isolated portably | Rejected before SQL | Claim outside the caller-owned transaction |
| Claim response is lost after execute/commit | A row may already be `running` | `IndeterminateStorageOutcomeException`; no second claim is attempted | Inspect/recover the first lease; do not issue an automatic second claim |
| Rollback itself fails while preserving a known statement failure | Commit was not reported; connection state may be unusable | Original known failure remains primary | Discard/reconnect the connection and inspect transaction state |

## Dispatch, reconciliation, and administration

| Boundary | Durable state | Notification state | Recovery |
|---|---|---|---|
| Notification enqueue fails after create | `pending` exists | Missing | Report the dispatch error; bounded reconciliation restores it |
| Future dispatch uses an unsupported driver | Unchanged | Unchanged | Capability preflight throws before storage mutation |
| Scheduled create commits but delayed notification fails | Future `pending` exists | Missing delayed entry | Reconciliation strictly parses UTC availability and restores delayed, not pending |
| Due job is already in delayed during startup | `pending` | One delayed member | Promotion runs before reconciliation, producing one pending notification |
| Pending membership lies beyond a bounded scan | `pending` | Existing member may be missed | A harmless duplicate can be restored; handlers must remain idempotent |
| Availability is missing/non-canonical | Unchanged | No new entry | Reconciliation increments `invalid` and advances its cursor |
| Administrative requeue notification fails | Fresh `pending`, attempts/error/progress reset | Missing | `QueueException`; reconciliation restores notification |
| Failed-job purge cleanup fails | Failed row is already deleted | Stale member may remain | `QueueException`; retry removal or let a later missing-row delivery be cleaned up |

`ReconcileResult::$duplicates` means an ID was already delayed or was found
inside the bounded pending scan. It is not a global proof of uniqueness.

## Worker effects

| Boundary | Durable transition | Event | Queue action | Recovery/meaning |
|---|---|---|---|---|
| Handler succeeds | `running -> completed` | `completed` | ACK | Normal terminal path |
| Handler fails with retry remaining | `running -> pending`, attempts `+1` | `retried` | NACK with delay | Promotion/claim performs the next execution |
| Handler fails on final attempt | `running -> failed`, attempts `+1` | `failed` | ACK | Administrative action is required |
| Handler returns an unserializable result | Fenced `running -> failed`, attempts `+1` | `failed` | ACK | Handler is not rerun merely to recreate its result |
| Completion storage call throws | Unknown/original claim state; never treated as handler failure | No completed/retried event | No ACK/NACK | `processOne()` throws; `run()` reports infrastructure and backs off |
| Retry/failure storage call throws | Original or indeterminate claim state | No durable-outcome event | No ACK/NACK | Stale recovery or explicit durable inspection |
| Any fenced transition returns `false` | New owner's state remains authoritative | `lost_ownership` with exact context | None | Old worker stops that path immediately |
| Progress update returns `false` | New owner's state remains authoritative | `lost_ownership(progress)` | None | Handler is interrupted by an internal ownership-loss exception |
| Progress storage call throws | Claim state is not assumed | No job outcome event | None | Infrastructure exception escapes; handler is stopped |
| Processing-heartbeat notification throws after durable progress | Progress/lease refresh is durable | `infrastructure_failure` | Handler continues | Storage fencing remains sufficient; queue visibility is repairable |
| Listener throws while receiving an event | Durable transition is unchanged | Delivery failure is logged/isolated | Normal ACK/NACK still runs | Telemetry never changes job state |
| ACK fails after completion/failure | Terminal transition remains authoritative and its event was attempted first | Already attempted | Stale processing member remains | Error escapes; later delivery cannot claim terminal storage and is cleaned up |
| NACK fails after retry scheduling | Pending retry remains authoritative and `retried` was attempted first | Already attempted | Processing member may remain | Error escapes; queue stale recovery restores delivery; availability still gates claim |
| Dequeued notification has no claimable row and ACK fails | Storage remains authoritative | No claimed event | Cleanup failed | Infrastructure exception escapes instead of masquerading as an empty queue |
| Signal arrives after claim but before handler | `running -> pending` with unchanged attempts and prior error metadata | Lost-ownership only if fenced release returns `false` | Immediate NACK after successful release | Handler is not started |
| Shutdown release storage/NACK throws | Release may be incomplete | Error log | No further action assumed | `run()` returns `EXIT_ERROR` so the supervisor reacts |
| Process crashes while `running` | Lease remains `running` until TTL | Processing member may remain | None | Storage and queue stale recovery consume one failed execution and retry/fail canonically |

All handler/middleware exceptions—including PDO/Redis exception classes thrown
by application code—are job failures because they occur inside the handler
boundary. Exceptions from Worker-owned storage, driver, maintenance, claim, or
cleanup calls are infrastructure failures. `processOne()` throws them; `run()`
emits `infra_error` and `backoff` and sleeps through the configured sleeper.

## Deterministic evidence

- `tests/Unit/PdoFaultHarnessTest.php` injects acquisition, prepare, execute,
  result-read, rollback, commit, and post-commit claim faults while recording
  durable rows, ownership, transaction state, and replay count.
- `tests/Support/StorageTransitionMatrix.php` runs canonical transitions across
  in-memory, SQLite, MySQL, and PostgreSQL.
- `tests/Unit/WorkerCompletionTest.php`, `WorkerFailureTest.php`,
  `WorkerOwnershipTest.php`, `WorkerObservabilityTest.php`, and
  `WorkerRunLoopTest.php` cover storage/notifier/listener/shutdown ordering.
- `tests/Unit/QueueReconcilerTest.php` covers optimized and legacy paths,
  delayed membership, invalid timestamps, bounded false negatives, cursor wrap,
  and duration exhaustion.
- `tests/Integration/FailurePathTest.php`, `FailureInjectionTest.php`, and
  `FailedJobAdministrationTest.php` cover composed lifecycle failures.

Handlers must make external side effects idempotent. Lease fencing protects
SimpleQueue state; it cannot roll back an email, payment, or external API call
that happened before a crash.
