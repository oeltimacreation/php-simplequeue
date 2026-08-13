# Internal type-safety boundaries

This document records the primitive, array-shape, and type-safety inventory for
v1.9.0. Public scalar signatures and serialized job data remain compatible;
normalization happens after values enter the library.

## PHP 8.2+ Audit & Invariants

- **Strict Types**: `declare(strict_types=1)` is verified across all 75 production source files and all 33 unit/integration test files.
- **Readonly Classes**: Immutable value objects (`DelayedBatch`, `IdempotentJobResult`, `WorkerOptions`, `ReconcileOptions`, `ReconcileResult`, `PositiveJobId`, `WorkerPolicy`, `ClaimedJob`, `JobData`) utilize `final readonly class` or `readonly` properties.
- **Language Compatibility**: Strictly targets PHP 8.2+ features (backed enums, standalone types, `readonly` classes). No PHP 8.3+ features (such as `#[Override]` or typed class constants) are used.

## Inventory and decisions

| Value or shape | Boundary | Internal representation | Decision |
|---|---|---|---|
| Job definitions | Dispatcher & storage `createJobs()` | `JobDefinitionShape` | Typed array shape `@phpstan-type JobDefinitionShape` enforcing `type`, `payload`, and optional `queue`, `maxAttempts`, `requestId`, `availableAt`. |
| Storage rows | PDO & in-memory hydration | `StorageRowShape` | Typed array shape `@phpstan-type StorageRowShape` in `JobDataHydrator` mapping database columns and types. |
| Positive job IDs | Dispatcher and queue-driver scalar arguments | `PositiveJobId` | Centralizes the `> 0` invariant while preserving each public error message. |
| Queue names | Public dispatcher, worker, and driver arguments | `string` | Remains scalar because v1.8 cannot trim or rewrite backend key names without changing behavior. Existing dispatch validation rejects empty names. |
| Worker IDs and lease tokens | Storage claims and fenced mutations | `ClaimedJob` | The existing immutable claim object keeps job, worker, and lease together. Separate wrappers would add no new invariant and would complicate custom storage compatibility. |
| Retry decisions | Worker policy | `RetryDecision` | Replaces a policy boolean with exhaustive `Retry` and `Fail` outcomes. |
| Fenced-write ownership | Storage result entering the worker | `OwnershipOutcome` | Converts the public storage boolean into exhaustive `Owned` and `Lost` outcomes before acknowledgement decisions. |
| Claim outcomes | Storage API | `?ClaimedJob` | Retained for public compatibility; a successful claim cannot omit its job, worker, or lease components. |
| Job status and terminal outcomes | Storage rows and `JobData` | `JobStatus` | In-memory state and dispatcher comparisons use enum cases instead of status strings. Terminal-state behavior remains owned by `JobStatus::isTerminal()`. |
| Storage rows | PDO and in-memory storage | `JobDataHydrator` and `StoredJobRow` | PDO/object rows cross one hydration boundary. In-memory rows have a complete PHPStan shape with typed status, counters, timestamps, ownership, progress, and serialized values. |
| Redis command results | Predis boundary | `RedisResponseNormalizer` | Scalar, null, malformed, and integer responses are normalized before queue orchestration uses them. |
| Timestamps | Clock and persistence boundaries | UTC database strings and integer backend scores | Retained because their format and comparison semantics are part of the storage and Redis protocols. `ClockInterface` remains the source of time. |
| Worker event payloads | Worker emitter and public event-listener callback | `WorkerEventInterface` plus typed readonly event value objects | Typed objects enforce each event's fields internally; the listener remains the documented `(string, array)` contract through `getName()` and `toArray()`. |
| Failed-job administration | Admin service and storage transition boundary | `FailedJobAdminInterface` plus `SupportsFailedJobAdministration` | The manager exposes operator actions while storage implementations guard the `failed` status transition; queue cleanup remains an explicit `SupportsJobRemoval` capability. |

## Trusted transitions

Raw PDO rows, storage objects, encoded payloads, and encoded results enter
`JobData` through `JobDataHydrator`. It supplies field defaults, converts
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

## Typed event boundary

`WorkerEventInterface` defines `fromArray()`, `getName()`, and `toArray()` for
the typed event value objects. The listener remains
`callable(string, array<string, mixed>): void`; the worker performs the
conversion only at that compatibility boundary. Contextual events may omit a
job ID when failure occurs before a claim exists.

| Event | Fields |
|---|---|
| `claimed` | `job_id`, `type`, `acquire_latency_ms` |
| `completed` | `job_id`, `type`, `duration_ms` |
| `retried` | `job_id`, `type`, `duration_ms`, `attempts`, `error` |
| `failed` | `job_id`, `type`, `duration_ms`, `error` |
| `lost_ownership` | `job_id`, `type`, `context` |
| `infrastructure_failure` | `job_id`, `context` |
| `infra_error` | `error`, `exception_class` |
| `backoff` | `error`, `backoff_seconds` |

The eight value objects are `JobClaimedEvent`, `JobCompletedEvent`,
`JobRetriedEvent`, `JobFailedEvent`, `JobLostOwnershipEvent`,
`InfrastructureFailureEvent`, `InfrastructureErrorEvent`, and
`WorkerBackoffEvent`. Their payload factories validate required field types.
Event payloads retain error messages and exception class names but never carry
`Throwable` instances or stack traces. PSR-14 was evaluated for this boundary
and intentionally not added: the callable listener is sufficient for the
framework-agnostic API and a dispatcher package would add runtime dependency
surface without a required use case.

## Compatibility constraints

Type-safety refactoring deliberately does not change public method signatures, named
arguments, serialized array keys, queue key construction, timestamp formats,
exception messages, or listener payloads. Internal wrappers were introduced
only where they enforce an invariant or make an outcome exhaustive. PHPStan
continues at Level 9 with strict rules and no new ignore pattern or ratchet
exception.
