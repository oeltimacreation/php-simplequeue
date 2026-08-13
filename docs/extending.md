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

## Middleware and execution context

Register middleware on the registry's ordered `JobMiddlewareRegistry`. The
worker runs middleware in registration order before the handler and in reverse
order after the handler:

```php
use Oeltima\SimpleQueue\Contract\JobContextInterface;
use Oeltima\SimpleQueue\Contract\JobMiddlewareInterface;

final class AuditMiddleware implements JobMiddlewareInterface
{
    public function process(JobContextInterface $context): mixed
    {
        $startedAt = microtime(true);
        $result = $context->proceed();

        error_log(sprintf(
            'Job %d (%s) attempt %d completed in %.2f ms',
            $context->getJobId(),
            $context->getType(),
            $context->getAttempts(),
            (microtime(true) - $startedAt) * 1000,
        ));

        return $result;
    }
}

$registry->middleware->register(new AuditMiddleware());
```

`JobContextInterface` provides typed access to the job ID, type, decoded
payload, queue, and one-based execution attempt. Call `proceed()` exactly once
to continue to the next middleware or handler. Middleware may run before and
after logic around that call and may return the continuation's result.

Registration order is deterministic: the first registered middleware is the
outermost wrapper. If middleware or the continuation throws, the exception
propagates to the worker's existing retry and permanent-failure handling path.
The context is populated from the claimed job before the first middleware runs.
With no registered middleware, the worker invokes the handler directly through
the unchanged v1.8 execution path. Middleware is a worker-layer feature, so it
does not add storage or queue-driver operations and behaves the same with
Redis, database, and in-memory drivers.

## Custom storage

Implement `JobStorageInterface` to store jobs elsewhere. The claim and fenced
write methods use `ClaimedJob`, whose lease token prevents an old worker from
modifying a job claimed by a newer worker. Implement optional capability
interfaces only when the storage supports their guarantees, including
`SupportsIdempotentJobCreation`, `SupportsPendingJobCursor`, and
`SupportsQueueScopedStaleRecovery`.

`JobStorageAdminInterface` adds listing, counting, and retention pruning for
operational tools.

`SupportsFailedJobAdministration` adds guarded reset and delete transitions for
failed jobs. Implement it together with `JobStorageAdminInterface` when a
custom store should be usable with `AdminManager`; the manager handles queue
re-notification and notification cleanup.

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

`SupportsDelayedJobs` adds `enqueueDelayed()`, `enqueueDelayedBatch()`, and
`promoteDelayedJobs()`. `enqueueDelayedBatch()` is additive: drivers that skip
it are still correct, because `QueueManager::enqueueDelayedBatch()` falls back
to one `enqueueDelayed()` call per job.

`AdminManager::purgeFailed()` requires `SupportsJobRemoval` so an administrative
purge can remove pending, delayed, and processing notifications. A storage-
gated driver such as `DatabaseQueueDriver` can implement the capability as a
validated no-op when it has no separate notification structure.

Use capability interfaces rather than adding methods to base contracts.
Document crash/recovery windows and preserve at-least-once semantics.

## Testing an integration

Use `InMemoryJobStorage` and `InMemoryQueueDriver` for fast lifecycle tests.
Add service-backed contract tests for production drivers or stores, especially
for concurrent claims, retries, cancellation, stale recovery, and a failure
between handler completion and acknowledgement.
