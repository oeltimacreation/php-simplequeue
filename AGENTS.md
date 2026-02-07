# AGENTS.md - AI Agent Instructions for PHP SimpleQueue

> This file provides instructions for AI coding assistants working on this project.

## Project Identity

- **Name**: OeltimaCreation PHP SimpleQueue
- **Version**: 1.2.0
- **Language**: PHP 8.1+
- **Type**: Framework-agnostic background job queue library
- **License**: MIT
- **Namespace**: `Oeltima\SimpleQueue`

## Quick Reference

| Component | Technology |
|-----------|------------|
| Queue Drivers | Redis (Predis), Database (PDO), InMemory |
| Storage | PDO (MySQL), InMemory |
| Testing | PHPUnit 10/11 |
| Static Analysis | PHPStan Level 8 |
| Code Style | PSR-12 (PHP_CodeSniffer) |
| CI | GitHub Actions (PHP 8.1, 8.2, 8.3, 8.4) |
| PSR Compliance | PSR-3 (Logger), PSR-11 (Container), PSR-4 (Autoloading) |

## Directory Structure

```
src/
├── Contract/       # Interfaces and value objects
│   ├── JobData.php                 # Value object for job data
│   ├── JobHandlerInterface.php     # Job handler contract
│   ├── JobStorageAdminInterface.php # Admin operations (list, count, prune)
│   ├── JobStorageInterface.php     # Core storage contract
│   └── QueueDriverInterface.php    # Queue driver contract
├── Driver/         # Queue driver implementations
│   ├── DatabaseQueueDriver.php     # PDO-based polling driver
│   ├── InMemoryQueueDriver.php     # In-memory driver for testing
│   └── RedisQueueDriver.php        # Redis-based driver (recommended)
├── Exception/      # Custom exceptions
│   ├── DriverNotAvailableException.php
│   ├── HandlerNotFoundException.php
│   └── QueueException.php
├── Storage/        # Job storage implementations
│   ├── InMemoryJobStorage.php      # In-memory storage for testing
│   └── PdoJobStorage.php           # PDO storage (MySQL)
├── JobDispatcher.php   # Dispatches jobs to the queue
├── JobRegistry.php     # Registers and resolves job handlers
├── QueueManager.php    # Central manager with factory methods
└── Worker.php          # Worker loop with signal handling

tests/
├── Unit/               # Isolated unit tests
│   ├── InMemoryQueueDriverTest.php
│   ├── InMemoryStorageTest.php
│   ├── JobDispatcherTest.php
│   ├── JobRegistryTest.php
│   ├── PdoJobStorageTest.php
│   ├── QueueManagerTest.php
│   ├── RedisQueueDriverTest.php
│   └── WorkerTest.php
└── Integration/        # Full lifecycle tests
    ├── CrashRecoveryTest.php
    └── JobLifecycleTest.php

examples/               # Usage examples
├── basic-usage.php
├── database-schema.sql
├── dispatch-jobs.php
└── redis-worker.php
```

## Essential Commands

```bash
# Run all tests
composer test

# Run with HTML coverage report
composer test-coverage

# Static analysis (PHPStan level 8)
composer phpstan

# Code style check (PSR-12)
composer cs-check

# Auto-fix code style
composer cs-fix
```

## Coding Patterns

### Strict Typing

All files MUST start with `declare(strict_types=1)`.

```php
<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue;
```

### Class Pattern

All classes should be `final` unless designed for extension. Use constructor property promotion where appropriate.

```php
<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue;

final class Example
{
    public function __construct(
        private readonly SomeDependency $dependency
    ) {}
}
```

### Interface Pattern

Interfaces go in `src/Contract/` and must have PHPDoc blocks on all methods.

```php
<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Contract;

interface ExampleInterface
{
    /**
     * Brief description.
     *
     * @param string $param Description
     * @return int Description
     */
    public function method(string $param): int;
}
```

### Exception Pattern

Custom exceptions extend `QueueException` (which extends `\RuntimeException`) and go in `src/Exception/`.

```php
<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Exception;

final class CustomException extends QueueException
{
    public static function specificCase(): self
    {
        return new self('Descriptive message');
    }
}
```

### Value Object Pattern

Value objects use `readonly` constructor properties and provide `fromRaw()` factory methods.

```php
final class JobData
{
    public function __construct(
        public readonly int $id,
        public readonly string $type,
        // ...
    ) {}

    public static function fromRaw(array|object $data): self { ... }
    public function toArray(): array { ... }
}
```

## Key Architecture Decisions

### Two-Layer Design

The library separates **queue** (notification/ordering) from **storage** (persistence):
- **QueueDriver**: Handles job ordering and notification (Redis lists/ZSET or DB polling)
- **JobStorage**: Handles job data persistence (PDO or in-memory)

Both must be provided to `Worker` and `JobDispatcher`.

### Driver Selection

`QueueManager` provides factory methods for driver selection:
- `QueueManager::redis($client)` — Redis driver
- `QueueManager::database($storage)` — Database polling driver
- `QueueManager::create('auto', $redis, $storage)` — Auto-select (Redis first, DB fallback)

### Connection Factory for Long-Running Workers

`PdoJobStorage` accepts `PDO|callable` — a callable factory is preferred for workers to handle stale connections:
```php
$storage = new PdoJobStorage(fn() => new PDO(...));
```

### In-Memory Implementations for Testing

`InMemoryJobStorage` and `InMemoryQueueDriver` provide full implementations for unit/integration testing without external dependencies.

## Testing Guidelines

### Test Naming Convention

```
test{MethodName}{Scenario}{ExpectedBehavior}
```

Example: `testDispatchCreatesJobInStorage`, `testWorkerRetriesFailedJob`

### Test Structure (Arrange-Act-Assert)

```php
public function testExampleBehavior(): void
{
    // Arrange
    $storage = new InMemoryJobStorage();
    $driver = new InMemoryQueueDriver();
    $queueManager = new QueueManager($driver);

    // Act
    $result = $someObject->method();

    // Assert
    $this->assertEquals('expected', $result);
}
```

### Using In-Memory Implementations

Always use `InMemoryJobStorage` and `InMemoryQueueDriver` for unit tests. Reserve real Redis/PDO for integration tests only.

### Test File Location

- Unit tests: `tests/Unit/{ClassName}Test.php`
- Integration tests: `tests/Integration/{ScenarioName}Test.php`

## Key Interfaces Reference

| Interface | Purpose | Implementations |
|-----------|---------|-----------------|
| `QueueDriverInterface` | Queue operations (enqueue, dequeue, ack, nack) | `RedisQueueDriver`, `DatabaseQueueDriver`, `InMemoryQueueDriver` |
| `JobStorageInterface` | Job persistence (CRUD, claim, status updates) | `PdoJobStorage`, `InMemoryJobStorage` |
| `JobStorageAdminInterface` | Admin ops (list, count, prune) | `PdoJobStorage`, `InMemoryJobStorage` |
| `JobHandlerInterface` | Business logic for job processing | User-implemented |

## Key Files Reference

| Purpose | File |
|---------|------|
| Queue Manager (entry point) | `src/QueueManager.php` |
| Job Dispatcher | `src/JobDispatcher.php` |
| Worker Loop | `src/Worker.php` |
| Handler Registry | `src/JobRegistry.php` |
| Job Value Object | `src/Contract/JobData.php` |
| Redis Driver | `src/Driver/RedisQueueDriver.php` |
| PDO Storage | `src/Storage/PdoJobStorage.php` |
| DB Schema | `examples/database-schema.sql` |
| CI Workflow | `.github/workflows/tests.yml` |

## Job Statuses

| Status | Description |
|--------|-------------|
| `pending` | Waiting to be processed |
| `running` | Currently being processed by a worker |
| `completed` | Successfully processed |
| `failed` | Failed after exhausting all retry attempts |
| `cancelled` | Manually cancelled |

## Common Tasks

### Adding a New Queue Driver

1. Implement `QueueDriverInterface` in `src/Driver/`
2. Add factory method to `QueueManager` if needed
3. Write unit tests in `tests/Unit/`
4. Update `README.md` with usage examples

### Adding a New Storage Backend

1. Implement `JobStorageInterface` (and optionally `JobStorageAdminInterface`) in `src/Storage/`
2. Write unit tests in `tests/Unit/`
3. Add integration tests in `tests/Integration/`

### Adding a New Exception

1. Create in `src/Exception/` extending `QueueException`
2. Use static factory methods for specific error cases
3. Make class `final`

### Modifying Interfaces

- Changes to `QueueDriverInterface` or `JobStorageInterface` are **breaking changes**
- Update ALL implementations (including `InMemory*` variants)
- Update CHANGELOG.md under `[Unreleased]` with `### Changed` and `BREAKING` prefix

## Dependencies

### Required (Runtime)

| Package | Purpose |
|---------|---------|
| `psr/container` ^1.1\|^2.0 | PSR-11 container interface for JobRegistry |
| `psr/log` ^2.0\|^3.0 | PSR-3 logger interface for Worker |

### Optional (Runtime)

| Package | Purpose |
|---------|---------|
| `predis/predis` ^2.0\|^3.0 | Redis queue driver |
| `ext-pdo` | Database job storage |
| `ext-pcntl` | Graceful worker shutdown on Unix |

### Dev Only

| Package | Purpose |
|---------|---------|
| `phpunit/phpunit` ^10.0\|^11.0 | Testing |
| `phpstan/phpstan` ^1.10 | Static analysis |
| `squizlabs/php_codesniffer` ^3.7 | Code style (PSR-12) |

## Notes for AI Agents

- **Library, not application**: This is a reusable library — no `.env`, no framework bootstrap, no application-specific code
- **Strict types everywhere**: Every PHP file must use `declare(strict_types=1)`
- **Final classes**: All concrete classes should be `final` unless explicitly designed for extension
- **PSR-12**: Follow PSR-12 coding standards strictly
- **PHPDoc required**: All public methods must have PHPDoc blocks with `@param` and `@return` tags
- **No framework coupling**: Do not introduce dependencies on any framework (Laravel, Symfony, etc.)
- **Predis is optional**: Redis-related code must handle `predis/predis` not being installed; PHPStan config ignores Predis errors
- **Backward compatibility**: Interface changes are breaking — document in CHANGELOG.md and consider carefully
- **In-memory parity**: `InMemoryJobStorage` and `InMemoryQueueDriver` must implement the same behavior as their real counterparts for reliable testing
