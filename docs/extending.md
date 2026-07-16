# Extending SimpleQueue

## Handlers and containers

Register a job type with a class implementing `JobHandlerInterface`. A
`JobRegistry` can resolve the class from a PSR-11 container; otherwise it
constructs the handler directly.

```php
$registry = new JobRegistry($container);
$registry->register('invoice.generate', GenerateInvoice::class);
```

Handlers receive the job ID, decoded payload, and an optional progress callback.
They must be idempotent because delivery is at least once.

## Custom storage

Implement `JobStorageInterface` to store jobs elsewhere. The claim and fenced
write methods use `ClaimedJob`, whose lease token prevents an old worker from
modifying a job claimed by a newer worker. Implement optional capability
interfaces only when the storage supports their guarantees, including
`SupportsIdempotentJobCreation`, `SupportsPendingJobCursor`, and
`SupportsQueueScopedStaleRecovery`.

`JobStorageAdminInterface` adds listing, counting, and retention pruning for
operational tools.

## Custom drivers

Implement `QueueDriverInterface` for a different delivery system. A driver
only transports job IDs; storage remains authoritative. Optional capabilities
advertise additional behavior without breaking third-party implementations:

- `SupportsBatchEnqueue`
- `SupportsDelayedJobs`
- `SupportsJobRemoval`
- `SupportsProcessingHeartbeat`
- `SupportsQueueReconciliation`
- `SupportsStaleRecovery`
- `SupportsTimeoutValidation`

Use capability interfaces rather than adding methods to base contracts.
Document crash/recovery windows and preserve at-least-once semantics.

## Testing an integration

Use `InMemoryJobStorage` and `InMemoryQueueDriver` for fast lifecycle tests.
Add service-backed contract tests for production drivers or stores, especially
for concurrent claims, retries, cancellation, stale recovery, and a failure
between handler completion and acknowledgement.
