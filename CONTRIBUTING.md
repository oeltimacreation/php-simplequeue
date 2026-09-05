# Contributing to PHP SimpleQueue

Thank you for contributing to PHP SimpleQueue! This document covers environment setup, maintained quality gates, and adding drivers or storage backends.

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
# Run tests, PHPStan, PHPCS, and deterministic operation budgets
composer check

# Run unit and integration tests
composer test

# Expose order-dependent state with the committed seed
composer test-random

# Run tests with explicit HTML coverage (also writes coverage/clover.xml)
composer test-coverage
composer coverage-check

# Run static analysis (PHPStan Level 9 with strict-rules)
composer phpstan

# Run code style check (PHPCS with Slevomat ruleset)
composer cs-check

# Auto-fix code style issues
composer cs-fix

# Run performance benchmarks
composer benchmark

# Enforce the small deterministic benchmark budget
composer budgets

# Capture the 10/100/1,000/10,000 non-gating profile
composer benchmark-profile

# Run focused mutation testing at the frozen baseline
composer mutation
```

---

## Maintained quality gates

PHPStan level 9 and PHPCS/Slevomat inspect source, tests, benchmarks, scripts,
and executable examples. Clover gates line and method coverage; Infection gates
critical transition behavior; Roave compares public/protected API against the
last stable tag; deterministic operation counts guard database, queue, Redis,
and event hot paths. The retired bespoke token analyzer is not part of v1.11.

### Quality Thresholds

For all new code additions:

- **Cyclomatic Complexity**: warning at 15; absolute maximum 25
- **Nesting Depth**: warning at 3; absolute maximum 5
- **Function Length**: maximum 100 lines in production source
- **Class Length**: maximum 500 lines in production source, with the retained
  `PdoJobStorage` v1 compatibility surface documented as the one exclusion
- **Coverage**: at least 83.9% lines and 71.2% methods
- **Mutation**: at least the frozen 64.79% focused baseline

Timing medians are evidence, not shared-runner gates. Do not loosen a
deterministic threshold to accommodate a change; explain and review any scoped
configuration exclusion. See [the quality guide](docs/quality-baseline.md).

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

Implement `Oeltima\SimpleQueue\Contract\QueueDriverInterface`. Add the backend
as a provider case in `tests/Contract/QueueDriverContractTest.php`, then cover
each advertised optional capability and its invalid inputs. Service-backed
drivers must fail—not skip—when their dedicated CI lane is configured. Future
dispatch requires `SupportsDelayedJobs` or
`SupportsStorageBackedScheduling`; claimed polling should implement
`SupportsWorkerAwareClaimedDequeue`.

### 3. Adding a Custom Job Storage Backend

Implement `Oeltima\SimpleQueue\Contract\JobStorageInterface`. Add it to
`tests/Contract/JobStorageContractTest.php` and run
`tests/Support/StorageTransitionMatrix.php` against it. A production backend
must prove atomic claims, lease fencing, canonical attempts, complete batch
rollback, result serialization, stale recovery, and direct API validation.
Implement lean cursor, scoped recovery, and administration capabilities only
when their documented guarantees are atomic.

---

## Pull Request Guidelines

1. Create a descriptive feature branch (`git checkout -b refactor/improve-driver-performance`).
2. Follow strict typing: every PHP file MUST begin with `declare(strict_types=1);`.
3. Use `final` classes by default unless explicitly designed for extension.
4. Ensure all public methods have PHPDoc blocks specifying `@param` and `@return` types.
5. Run `composer check` locally before submitting your pull request. PRs will not be merged unless all CI quality gates pass.
