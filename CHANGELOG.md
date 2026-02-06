# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
