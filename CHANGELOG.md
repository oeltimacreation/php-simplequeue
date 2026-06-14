# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- CI now runs PHPStan and PHPCS quality gates, with a new `composer check` aggregate for tests, static analysis, and style checks.
- Added repository hygiene files: `.editorconfig`, `SECURITY.md`, issue templates, and a pull request template.
- Added an injectable clock abstraction for UTC wall-clock timestamps and monotonic duration measurement.
- Added a `ClaimedJob` value object and `lease_token` schema support for fenced job ownership.
- Added a SQL migration example for upgrading existing MySQL, PostgreSQL, and SQLite job tables to lease-based claims.
- Added exit codes (`EXIT_SUCCESS`, `EXIT_ERROR`, `EXIT_LOCK_UNAVAILABLE`) to `Worker::run()` for better process supervision.
- Added worker recycling limits (`max_jobs`, `max_time`, `memory_limit`) and a `stop_when_empty` option to prevent unbounded memory growth and support clean process recycling.

### Changed

- **BREAKING**: `Worker::run()` now returns an integer exit code instead of `void`.
- **BREAKING**: New job schemas require `available_at` to be non-null and include `lease_token` for fenced claim ownership.
- **BREAKING**: `JobStorageInterface` methods (`markCompleted`, `markFailed`, `updateProgress`, `scheduleRetry`, `heartbeat`) now require a `ClaimedJob` value object instead of an integer `$id` to enforce fenced writes.
- **BREAKING**: Removed `getNextPendingJobId` and `claimJob` from `JobStorageInterface` in favor of `claimNextAvailable` and `claimById`.
- Rebuilt the worker execution loop to support backoff with jitter on infrastructure errors.
- Improved graceful worker shutdown to release lock in a `finally` block and immediately release any claimed job back to the pending queue.
- `PdoJobStorage` can now atomically claim the next available job or a specific queued job with a unique lease token.
- `InMemoryJobStorage` now matches lease-based claim semantics for tests and local development.
- `DatabaseQueueDriver` now delegates to the storage's atomic claim mechanism for polling, eliminating the thundering herd problem.
- GitHub Actions now tests the documented PHP 8.1 through 8.4 support range.
- Worker retry delay calculation is now centralized so storage and queue retry scheduling share one computed value.

### Fixed

- `composer test` now runs without coverage collection so missing coverage drivers do not fail the default test command.
- `JobData::fromRaw()` now normalizes scalar decoded payloads to an empty array instead of triggering a type error.
- `PdoJobStorage` now enforces `PDO::ERRMODE_EXCEPTION` for direct and factory-created connections.
- `PdoJobStorage` now avoids per-query health checks and reconnects only after connection-loss exceptions.

## [1.2.0] - 2026-02-07

### Added

- **Delayed retry support for Redis** - `nack()` now accepts optional `$delaySeconds` parameter to schedule retries with proper exponential backoff
- **Redis delayed queue** using ZSET (`:delayed`) to hold jobs until their retry time
- **Redis processing timestamp tracking** using ZSET (`:processing_z`) to enable stale job recovery
- `promoteDelayedJobs()` method on `RedisQueueDriver` to move due delayed jobs to pending queue
- `recoverStaleProcessing()` method on `RedisQueueDriver` to recover jobs stuck in processing after worker crash
- `getDelayedCount()` method on `RedisQueueDriver` to get count of delayed jobs
- **Idempotent dispatch** - `JobDispatcher::dispatchIdempotent()` returns existing active job instead of creating duplicates
- `findActiveByRequestId()` method on `JobStorageInterface` to find pending/running jobs by request correlation ID
- **`JobStorageAdminInterface`** - New interface for administrative operations (`list()`, `count()`, `pruneCompleted()`)
- `InMemoryJobStorage` now implements `JobStorageAdminInterface` with full filter support
- `PdoJobStorage` now implements `JobStorageAdminInterface` (methods already existed, now formalized)
- **DB poll interval option** - `QueueManager::create()` and `QueueManager::database()` now accept `pollIntervalMs` parameter for database driver tuning
- **Batch enqueue with Redis pipeline** - `RedisQueueDriver::enqueueBatch()` uses Redis pipeline for efficient multi-job enqueueing
- `JobDispatcher::dispatchBatch()` automatically uses pipeline optimization when Redis driver is active
- **InMemoryQueueDriver** enhanced with `promoteDelayedJobs()`, `recoverStaleProcessing()`, and delayed job tracking for integration testing
- **Integration test suite** - Full job lifecycle tests (dispatch → process → complete) and crash recovery scenarios
- **GitHub Actions CI** - Automated test runs on PHP 8.1, 8.2, 8.3, 8.4
- Expanded unit tests for Worker (retry, exponential backoff, max attempts), QueueManager (driver selection), and Redis/InMemory drivers

### Changed

- **BREAKING**: `QueueDriverInterface::nack()` signature changed to `nack(string $queue, int $jobId, int $delaySeconds = 0): void`
- **BREAKING**: `JobStorageInterface` now requires `findActiveByRequestId(string $requestId): ?JobData`
- `PdoJobStorage::claimJob()` now checks `available_at` column to prevent claiming jobs before their scheduled retry time

### Removed

- `JobFailedException` - Unused exception class removed

### Fixed

- **Redis retry delays ignored** - Previously `nack()` immediately re-enqueued jobs, defeating exponential backoff. Now respects delay via ZSET
- **Redis stale job recovery** - After worker crash, jobs are now properly recovered from Redis processing list back to pending
- **`processOne()` blocks forever with Redis** - Now uses non-blocking `RPOPLPUSH` when `timeout=0` instead of blocking `BRPOPLPUSH`
- **Storage/driver exceptions crash worker** - All storage and driver calls in `processJob()` and `handleJobFailure()` are now wrapped in try/catch
- Worker now calls `promoteDelayedJobs()` before each dequeue to move due delayed jobs to pending
- Worker now calls `recoverStaleProcessing()` on driver during stale job recovery

## [1.1.0] - 2026-02-03

### Added

- **Connection Factory Pattern** for `PdoJobStorage` - Constructor now accepts `PDO|callable`, allowing a factory function to be passed that creates fresh database connections on demand
- **Auto-reconnect** for long-running workers - Automatically detects stale MySQL connections ("MySQL server has gone away") and reconnects transparently
- `reconnect()` method on `PdoJobStorage` to force a new connection on the next database operation
- Comprehensive unit tests for `PdoJobStorage` including reconnection scenarios

### Fixed

- **MySQL "server has gone away" crashes** in long-running workers when the database connection times out during idle periods

## [1.0.0] - 2026-01-08

### Added

- Initial release
- `QueueManager` with factory methods for Redis and database drivers
- `Worker` class with graceful shutdown and signal handling
- `JobDispatcher` for dispatching jobs to the queue
- `JobRegistry` for registering job handlers with PSR-11 container support
- `RedisQueueDriver` using Redis lists with BRPOPLPUSH for reliable delivery
- `DatabaseQueueDriver` with polling for fallback scenarios
- `InMemoryQueueDriver` for testing
- `PdoJobStorage` for database-backed job storage
- `InMemoryJobStorage` for testing
- `JobData` value object for job data representation
- `JobStorageInterface` for custom storage implementations
- `QueueDriverInterface` for custom driver implementations
- `JobHandlerInterface` for job handler implementations
- Automatic retry with exponential backoff
- Progress tracking with percentage and message
- Stale job recovery
- Singleton worker with file locking
- Comprehensive documentation and examples
