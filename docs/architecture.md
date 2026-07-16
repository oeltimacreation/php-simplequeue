# Architecture

See [getting started](getting-started.md) for setup and
[operations](operations.md) for deployment and repair guidance.

## Boundaries and source of truth

Job storage is authoritative for job data, status, leases, retry state, and
results. A queue driver is a delivery notification and ordering layer. A
successful database insert followed by a failed enqueue is a repairable
dual-write gap; it does not make Redis authoritative. In-memory implementations
model the same split for tests.

## Delivery and ownership

Delivery is at least once. A worker claims a pending row with a worker ID and
lease token, executes the registered handler, then fences completion, retry,
progress, or failure updates with that claim. A crash after handler execution
but before acknowledgement can execute a handler again, so handlers must be
idempotent. Acknowledgement removes the notification; it does not replace the
storage state transition.

## Leases and long jobs

Running jobs have a lease in storage and a processing visibility marker in
drivers that support it. Progress callbacks are the cooperative refresh point.
Handlers longer than `stuck_job_ttl` should report progress at least often
enough to leave room for maintenance cadence and network jitter, or operators
should choose a larger TTL. Workers do not interrupt a synchronous handler and
therefore do not promise a hard timeout.

## Drivers and scaling

Redis uses pending/processing lists and delayed/processing sorted sets. The
database driver polls storage and claims rows there. The worker singleton lock
is a local file lock: scale by using one lock file per independent worker/queue
and coordinate process counts with the supervisor. The current Redis key layout
does not promise Redis Cluster hash-slot compatibility.
