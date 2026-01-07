# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2025-01-08

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
