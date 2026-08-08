# Contributing to PHP SimpleQueue

Thank you for contributing to PHP SimpleQueue! This document provides guidelines for environment setup, code quality enforcement, quality ratchets, and adding new drivers or storage backends.

---

## Local Development Setup

### Requirements

- **PHP**: 8.2 or higher with `pdo`, `pdo_sqlite` extensions (and `pcntl` on Unix systems)
- **Composer**: 2.x
- **Redis / Valkey (Optional)**: Required only for running integration tests against a real Redis instance

### Initial Setup

```bash
# Clone the repository
git clone https://github.com/oeltimacreation/php-simplequeue.git
cd php-simplequeue

# Install development dependencies
composer install

# Verify your installation with the full quality suite
composer check
```

---

## Quality Toolchain & Testing Program

SimpleQueue maintains strict quality gates across static analysis, unit/integration testing, coding standards, and complexity limits.

```bash
# Run the complete quality gate suite (PHPUnit, PHPStan, PHPCS, Quality Ratchet)
composer check

# Run unit and integration tests
composer test

# Run tests with HTML coverage report (outputs to coverage/html)
composer test-coverage

# Run static analysis (PHPStan Level 9 with strict-rules)
composer phpstan

# Run code style check (PHPCS with Slevomat ruleset)
composer cs-check

# Auto-fix code style issues
composer cs-fix

# Generate physical code complexity and duplication report
composer quality-report

# Enforce quality ratchet rules against quality/quality-baseline.json
composer quality-ratchet

# Run performance benchmarks
composer benchmark
```

---

## Code Quality Ratchet System

SimpleQueue uses an internal dependency-free code analyzer (`composer quality-ratchet`) based on `token_get_all()` to enforce complexity and duplicate-window limits.

### Quality Thresholds

For all new code additions:

- **Cognitive Complexity**: Max 15 per method
- **Cyclomatic Complexity**: Max 15 per method
- **Nesting Depth**: Max 3 control-flow levels
- **Method Length**: Max 100 lines per method
- **Class Length**: Max 500 lines per class (new classes)
- **Production Duplication**: 0 new duplicate 50-token windows allowed

### Ratchet Policy

Existing grandfathered hotspots cannot increase in complexity or line count. If a refactor reduces complexity, the quality baseline is updated via `composer quality-report` and committed to lock in the improvement.

Exceptions to ratchet rules are rare and must be explicitly documented with a justification in `quality/ratchet-exceptions.json`.

---

## Extending SimpleQueue & Contract Testing

### 1. Adding a Custom Job Handler

Implement `Oeltima\SimpleQueue\Contract\JobHandlerInterface` and register it with `JobRegistry`:

```php
<?php

declare(strict_types=1);

namespace App\Queue;

use Oeltima\SimpleQueue\Contract\JobHandlerInterface;

final class ProcessReportHandler implements JobHandlerInterface
{
    public function handle(int $jobId, array $payload, ?callable $progress = null): mixed
    {
        // 1. Optional progress reporting
        if ($progress !== null) {
            $progress(percent: 50, message: 'Processing report data');
        }

        // 2. Perform work
        return ['status' => 'success', 'report_id' => $payload['report_id']];
    }
}
```

### 2. Adding a Custom Queue Driver

Implement `Oeltima\SimpleQueue\Contract\QueueDriverInterface`. To verify compliance with driver behavior, write a unit test extending `Oeltima\SimpleQueue\Tests\Contract\QueueDriverContractTest`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Driver;

use Oeltima\SimpleQueue\Contract\QueueDriverInterface;
use Oeltima\SimpleQueue\Tests\Contract\QueueDriverContractTest;

final class CustomQueueDriverTest extends QueueDriverContractTest
{
    protected function createDriver(): QueueDriverInterface
    {
        return new CustomQueueDriver();
    }
}
```

### 3. Adding a Custom Job Storage Backend

Implement `Oeltima\SimpleQueue\Contract\JobStorageInterface`. Verify persistence contract compliance by extending `Oeltima\SimpleQueue\Tests\Contract\JobStorageContractTest`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Storage;

use Oeltima\SimpleQueue\Contract\JobStorageInterface;
use Oeltima\SimpleQueue\Tests\Contract\JobStorageContractTest;

final class CustomJobStorageTest extends JobStorageContractTest
{
    protected function createStorage(): JobStorageInterface
    {
        return new CustomJobStorage();
    }
}
```

---

## Pull Request Guidelines

1. Create a descriptive feature branch (`git checkout -b refactor/improve-driver-performance`).
2. Follow strict typing: every PHP file MUST begin with `declare(strict_types=1);`.
3. Use `final` classes by default unless explicitly designed for extension.
4. Ensure all public methods have PHPDoc blocks specifying `@param` and `@return` types.
5. Run `composer check` locally before submitting your pull request. PRs will not be merged unless all CI quality gates pass.

