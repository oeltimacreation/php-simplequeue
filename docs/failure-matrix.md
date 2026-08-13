# Failure and recovery matrix

SimpleQueue treats storage as the source of truth and queue notifications as
repairable, at-least-once delivery hints. The matrix below records the expected
state at each failure boundary. “ACK” includes removing a notification that no
longer represents claimable work.

| Failure boundary | Durable storage state | Queue state | ACK/NACK decision | Recovery path | Observable signal |
|---|---|---|---|---|---|
| Storage create/write fails before dispatch notification | No new job; the previous durable state is unchanged | No notification is added | None | Caller may retry the operation | Original storage exception |
| Notification enqueue fails after storage commit | `pending` job exists | Notification is missing | None | Bounded reconciliation restores the notification; idempotent dispatch avoids a second active job | Dispatch exception; reconciliation log reports `restored` |
| Crash after scheduled storage create before delayed notification | `pending` with a future `available_at` | Delayed notification is missing | None | Bounded reconciliation parses `available_at` as UTC and restores the notification into the delayed structure with the remaining delay — never into pending | Dispatch exception; reconciliation log reports `restored` |
| Duplicate notification after a retry dispatch | `pending` with a future `available_at` and incremented attempts | Notification exists in delayed (and optionally a stale pending member) | None | Reconciliation detects the existing delayed notification and reports a duplicate instead of adding another; storage claims gate on `available_at` | `Duplicate` outcome; no extra notification |
| Dispatcher/worker clock skew | `pending` with an absolute UTC `available_at` | Delayed notification scored by the dispatcher clock | None | Claim queries compare the worker clock with the stored absolute `available_at`; a worker clock behind cannot claim early, a clock ahead may claim up to the skew amount early | Early/late claim timings; availability remains absolute time |
| Past/now schedule clamping | `pending` with `available_at` equal to now | Immediate notification | None | The schedule path is skipped entirely; the storage claim is immediately eligible | Immediate dispatch path unchanged |
| Cancel of a scheduled job | `cancelled`, ownership cleared, completion time set | Delayed notification removed | Removal of the delayed member | Repeating cancellation retries idempotent notification removal | `cancelJob()` returns `true` |
| Max-attempts exhaustion with scheduled retries | `failed` after the final attempt; retry delays kept the job out of `pending` while waiting | Processing notification removed after the fenced failure write | ACK only after the fenced terminal write | No further notifications are produced; administrative redispatch may re-schedule | Failure log plus `failed` event |
| Failed job is re-queued administratively | `pending`, attempts reset to `0`, terminal/error/progress fields cleared | A fresh notification is enqueued | None beyond normal worker claim | The job follows the ordinary claim, retry, and completion path | `AdminManager::requeueFailed()` returns `true` |
| Failed-job re-queue notification fails | `pending` reset is durable | No new notification | None | Bounded reconciliation restores the pending notification; the re-queue call reports a `QueueException` | Deterministic fault injection in `FailedJobAdministrationTest` |
| Failed job is purged administratively | Row is deleted | Pending, delayed, and processing notifications are removed | None | Purge is terminal; repeated purge is idempotent and returns `false` | `AdminManager::purgeFailed()` returns `true` |
| Failed-job purge cleanup fails | Failed row has already been deleted | A stale notification may remain | Cleanup exception is reported; no storage rollback | Queue removal retry or a worker later ACKs the notification after the missing row is observed | Deterministic fault injection in `FailedJobAdministrationTest` |
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
driver in `tests/Integration/FailurePathTest.php`. The scheduled-dispatch rows
are covered deterministically there: delayed-notification failure after a
scheduled storage create is repaired into the delayed structure, a duplicate
notification after a retry dispatch is not amplified, a worker clock behind
the dispatcher cannot claim early, past/now schedules follow the immediate
path, cancelling a scheduled job removes the delayed notification, and
max-attempts exhaustion stops rescheduling. UTC-safe reconciliation timestamp
parsing is characterized in `tests/Unit/QueueReconcilerTest.php` under
non-UTC default timezones. Crash recovery, lease
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

`tests/Contract/FailedJobAdminContractTest.php` compares failed-job listing,
inspection, reset, purge, and notification cleanup across in-memory and PDO
storage. `tests/Integration/FailedJobAdministrationTest.php` injects queue
failures during re-queue and purge.

## Operational rule

Handlers must remain idempotent. Recovery can deliberately produce duplicate
notifications, but fenced storage writes prevent an expired worker from
overwriting the current owner. Logs and listener events contain identifiers,
timings, transition context, error messages, and exception class names; they do
not include payloads, results, throwable objects, credentials, or stack data.
