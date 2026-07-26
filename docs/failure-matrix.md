# Failure and recovery matrix

SimpleQueue treats storage as the source of truth and queue notifications as
repairable, at-least-once delivery hints. The matrix below records the expected
state at each failure boundary. “ACK” includes removing a notification that no
longer represents claimable work.

| Failure boundary | Durable storage state | Queue state | ACK/NACK decision | Recovery path | Observable signal |
|---|---|---|---|---|---|
| Storage create/write fails before dispatch notification | No new job; the previous durable state is unchanged | No notification is added | None | Caller may retry the operation | Original storage exception |
| Notification enqueue fails after storage commit | `pending` job exists | Notification is missing | None | Bounded reconciliation restores the notification; idempotent dispatch avoids a second active job | Dispatch exception; reconciliation log reports `restored` |
| Connection is lost while claiming after a notification is popped | Job normally remains `pending`; a completed claim transaction remains authoritative if the response was lost | Notification is in processing | Immediate NACK when possible | PDO reconnect retries recognized connection-loss errors once; failed NACK is repaired by stale queue recovery and storage fencing rejects duplicate claims | Claim/requeue error log; loop-level infrastructure event and bounded backoff |
| Backend returns a malformed notification or stored JSON | Valid durable jobs are unchanged; malformed stored JSON cannot be hydrated | Malformed Redis member is removed instead of becoming job `0` | Discard malformed member; do not ACK a valid job | Producer/data repair is required for malformed durable JSON; queue processing continues after malformed notification cleanup | Contextual `SerializationException`, or a `null` dequeue plus removed malformed member |
| Handler throws before the final attempt | `pending`, attempts incremented, retry time and error recorded | Processing notification moves to delayed/pending | NACK with the selected delay, only after durable retry scheduling | Delayed promotion and normal worker processing | Failure log plus `retried` event |
| Handler throws on the final attempt | `failed`, error and bounded trace stored, ownership released | Processing notification removed | ACK only after the fenced terminal write | Administrative inspection or redispatch | Failure log plus `failed` event |
| Handler succeeds but result JSON cannot be encoded | `failed` with serialization error; never transiently `completed` | Processing notification removed | ACK after the fenced failure write | Correct the handler result and redispatch | Result-serialization error log |
| Lease is lost before progress, completion, retry, or failure write | New owner’s durable state remains authoritative | Old delivery is left untouched so the new owner’s notification is not removed | No ACK/NACK by the stale owner | New owner completes, or bounded stale recovery restores work | Warning plus `lost_ownership` event with transition context |
| Process stops after claim but before a storage transition | `running` with the old lease | Notification remains processing | No ACK/NACK occurred | Storage and queue stale-recovery passes return the job to `pending`; max attempts bound repeated crashes | Stale-recovery count/log |
| Process stops after a retry write but before NACK | `pending` with incremented attempts and delay | Notification remains processing | NACK did not occur | Queue stale recovery restores delivery; storage claim enforces `available_at` | Recovery count; duplicate execution is fenced |
| Process stops after completion/failure write but before ACK | Terminal durable state | Notification remains processing | ACK did not occur | Queue stale recovery redelivers; failed claim causes the duplicate notification to be ACKed without handler execution | ACK error log followed by duplicate cleanup |
| Retry/failure storage transition itself fails | Original `running` state and lease remain | Notification remains processing | No ACK/NACK | Storage and queue stale recovery retry the attempt after the TTL | Job failure log plus storage-transition error log |
| Cancellation cleanup fails after durable cancellation | `cancelled`, ownership cleared, completion time set | Stale pending/delayed/processing notification remains | Removal failed | Repeating cancellation retries idempotent notification removal | `QueueException` states that cancellation is durable but cleanup failed |

## Deterministic coverage

The transition boundaries are exercised with an in-memory fault-injecting
driver in `tests/Integration/FailurePathTest.php`. Crash recovery, lease
fencing, duplicate delivery, cursor continuation, and backend contracts are
also covered by:

- `tests/Integration/CrashRecoveryTest.php`;
- `tests/Integration/ConcurrencyTest.php`;
- `tests/Unit/QueueReconcilerTest.php`;
- `tests/Contract/JobStorageContractTest.php` and
  `tests/Contract/QueueDriverContractTest.php`.

`tests/Integration/WorkerSoakTest.php` repeatedly recycles workers, reconnects
PDO factories, checks memory growth, drives a long infrastructure-error
sequence, and sends a real `SIGTERM`. Existing deterministic worker tests keep
maintenance cadence and infrastructure retry/reset behavior observable without
wall-clock-dependent assertions.

## Operational rule

Handlers must remain idempotent. Recovery can deliberately produce duplicate
notifications, but fenced storage writes prevent an expired worker from
overwriting the current owner. Logs and listener events contain identifiers,
timings, transition context, error messages, and exception class names; they do
not include payloads, results, throwable objects, credentials, or stack data.
