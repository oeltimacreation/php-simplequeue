# Internal type-safety boundaries

This document records the primitive and array-shape inventory for
v1.6.0. Public scalar signatures and serialized job data remain compatible;
normalization happens after values enter the library.

## Inventory and decisions

| Value or shape | Boundary | Internal representation | Decision |
|---|---|---|---|
| Positive job IDs | Dispatcher and queue-driver scalar arguments | `PositiveJobId` | Centralizes the `> 0` invariant while preserving each public error message. |
| Queue names | Public dispatcher, worker, and driver arguments | `string` | Remains scalar because v1.6 cannot trim or rewrite backend key names without changing behavior. Existing dispatch validation rejects empty names. |
| Worker IDs and lease tokens | Storage claims and fenced mutations | `ClaimedJob` | The existing immutable claim object keeps job, worker, and lease together. Separate wrappers would add no new invariant and would complicate custom storage compatibility. |
| Retry decisions | Worker policy | `RetryDecision` | Replaces a policy boolean with exhaustive `Retry` and `Fail` outcomes. |
| Fenced-write ownership | Storage result entering the worker | `OwnershipOutcome` | Converts the public storage boolean into exhaustive `Owned` and `Lost` outcomes before acknowledgement decisions. |
| Claim outcomes | Storage API | `?ClaimedJob` | Retained for public compatibility; a successful claim cannot omit its job, worker, or lease components. |
| Job status and terminal outcomes | Storage rows and `JobData` | `JobStatus` | In-memory state and dispatcher comparisons use enum cases instead of status strings. Terminal-state behavior remains owned by `JobStatus::isTerminal()`. |
| Storage rows | PDO and in-memory storage | `JobDataHydrator` and `StoredJobRow` | PDO/object rows cross one hydration boundary. In-memory rows have a complete PHPStan shape with typed status, counters, timestamps, ownership, progress, and serialized values. |
| Redis command results | Predis boundary | `RedisResponseNormalizer` | Scalar, null, malformed, and integer responses are normalized before queue orchestration uses them. |
| Timestamps | Clock and persistence boundaries | UTC database strings and integer backend scores | Retained because their format and comparison semantics are part of the storage and Redis protocols. `ClockInterface` remains the source of time. |
| Worker event payloads | Public event-listener callback | Documented associative arrays | Retained because listeners consume the array contract. Event names determine the payload shape; converting them to internal objects would create an unnecessary public conversion layer. |

## Trusted transitions

Raw PDO rows, storage objects, encoded payloads, and encoded results now enter
`JobData` only through `JobDataHydrator`. It supplies field defaults, converts
status values to `JobStatus`, validates payload object keys, and reports invalid
JSON through the existing `SerializationException` messages.

In-memory storage retains encoded payload/result parity with PDO storage, but
its private row has a complete `StoredJobRow` shape. Status transitions use
`JobStatus` cases, counters stay integers, and releasing a claim clears the
worker, lock timestamp, and lease token together. This removes the casts and
numeric checks that previously treated trusted private state as untyped input.

The worker maps retry eligibility and fenced mutation results to exhaustive
enums before choosing retry, failure, ACK, or lost-ownership paths. Public
storage methods still return booleans, and public queue/storage methods still
accept integers and strings, so custom implementations and named arguments are
unchanged.

## Event payload shapes

The listener remains `callable(string, array<string, mixed>): void`. These are
the stable fields emitted by the worker; contextual events may omit a job ID
when failure occurs before a claim exists.

| Event | Fields |
|---|---|
| `claimed` | `job_id`, `type`, `acquire_latency_ms` |
| `completed` | `job_id`, `type`, `duration_ms` |
| `retried` | `job_id`, `type`, `duration_ms`, `attempts`, `error` |
| `failed` | `job_id`, `type`, `duration_ms`, `error` |
| `lost_ownership` | `job_id`, `type`, `context` |
| `infrastructure_failure` | `job_id`, `context` |
| `infra_error` | `error`, `exception` |
| `backoff` | `error`, `backoff_seconds` |

## Compatibility constraints

Type-safety refactoring deliberately does not change public method signatures, named
arguments, serialized array keys, queue key construction, timestamp formats,
exception messages, or listener payloads. Internal wrappers were introduced
only where they enforce an invariant or make an outcome exhaustive. PHPStan
continues at Level 9 with strict rules and no new ignore pattern or ratchet
exception.
