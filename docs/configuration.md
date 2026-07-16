# Configuration

## Queue drivers

`QueueManager` selects the notification layer. `PdoJobStorage` remains the
source of truth for job records with every driver.

```php
// Redis or Valkey: recommended when a server is available.
$queues = QueueManager::redis($redis, 'app');

// Database polling: no Redis dependency.
$queues = QueueManager::database($storage, pollIntervalMs: 250);

// Try Redis and fall back to database polling.
$queues = QueueManager::create('auto', $redis, $storage, redisPrefix: 'app');
```

For a Redis worker with a positive `poll_timeout`, configure Predis
`read_write_timeout` higher than the poll timeout or use `-1`. Do not combine
Predis's global key prefix with SimpleQueue's `redisPrefix` without planning
the resulting keys.

## Worker options

Pass an array to `Worker` for compatibility, or create validated options with
`WorkerOptions` and `Worker::withOptions()`. Numeric strings are accepted in
the array form for environment-based configuration.

| Option | Default | Meaning |
|---|---:|---|
| `poll_timeout` | `5` | Seconds a driver can wait for work; `0` is non-blocking. |
| `stuck_job_ttl` | `600` | Positive lease age after which a running job may be recovered. |
| `retry_base_delay` | `2` | Initial retry delay in seconds. |
| `retry_max_delay` | `300` | Maximum retry delay in seconds. |
| `lock_file` | queue-scoped `/tmp` path | Set a unique path per worker/queue; use `null` only for controlled tests. |
| `max_jobs` | `0` | Stop after this many jobs; `0` disables the limit. |
| `max_time` | `0` | Stop after this many seconds; `0` disables the limit. |
| `memory_limit` | `0` | Stop once PHP memory exceeds this many MB; `0` disables the limit. |
| `stop_when_empty` | `false` | Stop instead of continuing to poll after no job is found. |
| `promote_interval` | `5.0` | Minimum seconds between delayed-job promotion passes. |
| `recovery_interval` | `60.0` | Minimum seconds between recovery and reconciliation passes. |
| `event_listener` | `null` | Callable receiving `(string $event, array $data)`. Listener failures are logged. |

`Worker::run()` returns `0` on a normal stop or configured limit, `1` on an
unhandled worker error, and `2` when its singleton lock cannot be acquired.

## Dispatching and progress

```php
$jobId = $dispatcher->dispatch(
    type: 'invoice.generate',
    payload: ['invoice_id' => 42],
    queue: 'billing',
    maxAttempts: 5,
    requestId: 'request-3dbbf5',
);

$result = $dispatcher->dispatchIdempotent(
    type: 'invoice.generate',
    payload: ['invoice_id' => 42],
    requestId: 'invoice:42',
);
```

`dispatchIdempotent()` returns `['job_id' => int, 'created' => bool]`.
`cancelJob()` only cancels pending jobs. Built-in drivers remove the
notification after durable cancellation; retrying cancellation can repair a
prior cleanup failure.

The optional progress callback accepts a percentage from `0` to `100` and a
message. Handlers expected to exceed `stuck_job_ttl` should report progress at
least every half TTL or use a larger TTL. Retries use capped exponential
backoff based on `retry_base_delay` and `retry_max_delay`.
