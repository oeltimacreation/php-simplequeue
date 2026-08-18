# Quality Baseline & Inventory (v1.10.0)

This report records the quality baseline and inventory for the current **v1.10.0** work. The machine-readable baseline is stored in [`quality/quality-baseline.json`](../quality/quality-baseline.json). The v1.9.0 figures below are retained as historical comparison data.

## Reproduce the inventory

The project uses a dependency-free analyzer built on PHP's `token_get_all()`.
Adding a third-party cognitive-complexity or copy/paste detector was rejected
for this baseline because the existing PHP quality dependencies do not provide
both measures and an additional development dependency was not justified for
an internal ratchet.

```bash
composer quality-report
composer quality-ratchet
php scripts/quality-metrics.php write-baseline quality/quality-baseline.json
```

`quality-ratchet` first runs the analyzer fixtures and then checks the stored
baseline. The fixture tree is outside `src/` and `tests/`, so it characterizes
the analyzer without changing the repository inventory.

## Analyzer protection map

| Analyzer part | Protected property | Characterization |
|---|---|---|
| Token normalization | Branch, cognitive, nesting, and method-size metrics | `scripts/fixtures/quality-metrics/src/complexity.php` and `nesting.php` |
| Class traversal | Physical class boundaries and class-size metrics | `scripts/fixtures/quality-metrics/src/class-size.php` |
| Duplicate windows | Normalized overlapping 50-token fingerprints across methods | `scripts/fixtures/quality-metrics/src/duplicates.php` |
| CLI and baseline writer | `report`, `write-baseline`, and `check` workflows with schema version 1 | `scripts/quality-metrics-fixtures.php` plus the ratchet command |

The analyzer keeps the baseline schema and all three command workflows. Its
token categories are defined once, duplicate occurrences are merged directly,
and a missing `src/` or `tests/` directory is skipped so isolated fixtures do
not need placeholder files.

## v1.10.0 Inventory

The baseline was regenerated after the current worker/event implementation and
tooling audit landed:

| Scope | PHP files | Classes | Methods | Physical lines | Duplicated windows |
|---|---:|---:|---:|---:|---:|
| Production | 81 | 81 | 351 | 7,564 | **0** |
| Tests | 37 | 64 | 447 | 7,881 | 1,058 |

All production methods remain within the 15/15/3 complexity and 100-line
ratchet targets, and production duplication remains at zero. The stored
baseline also records the existing, narrowly scoped Worker class-size
exception for its lazy event path; no broad exception or suppression was added.

`quality-report` scans every PHP file below `src/` and `tests/`. The analyzer
uses these stable definitions:

- cyclomatic complexity starts at one and increments for branches, cases,
  catches, ternaries, coalescing, and boolean decision operators;
- cognitive complexity increments for control-flow breaks, their nesting,
  boolean-operator sequences, and direct recursion;
- nesting depth is the maximum brace-delimited control-flow depth;
- method and class size are physical lines from declaration to closing brace;
- duplication is an overlapping 50-token window after comments and whitespace
  are removed and variables and literals are normalized. Window counts are a
  change detector, not a duplicated-line percentage.

The stored baseline contains every class, method, metric, and duplicated-window
fingerprint. The check compares current code with that file and fails when an
existing method metric grows, a production class grows, a new method or class
exceeds the target, or a new duplicated window appears. An intentional
exception must be narrowly recorded with its metric and reason in
`quality/ratchet-exceptions.json`; the checker prints every applied exception.

## Simplification audit decisions

| Surface | Decision and retained safety property |
|---|---|
| PHPUnit | One `phpunit.xml.dist` owns suites, source inclusion, and coverage reports. `composer test` remains explicitly no-coverage; `composer test-coverage` explicitly enables HTML output and still writes Clover and text reports. |
| Benchmarks | Counter normalization is shared in the runner. Scenario names, JSON result fields, fixture cleanup, and hard operation-count assertions are unchanged. |
| Composer and CI | Composer scripts use Composer's current PHP interpreter. The CI matrix retains PHP 8.2–8.5, Redis, Valkey, MySQL, PostgreSQL, lowest dependencies, audit, coverage, examples, and the `Quality gates` job name. Explicit matrix metadata preserves the Redis/MySQL and Valkey/PostgreSQL concurrency pairings, while per-ref cancellation avoids superseded runs. |
| Static analysis and style | The deprecated PHPUnit assertion ignore was removed. The PHPCS test line-length exclusion was characterized as active and retained; unmatched-ignore reporting, other test-only exclusions, and the optional Predis boundary remain explicit. |

These choices reduce configuration layers without hiding service dimensions or
removing behavior, backend-parity, coverage, static-analysis, or ratchet gates.

## Historical v1.9.0 Inventory Summary

The v1.9.0 release snapshot is retained for comparison only:

| Scope | PHP files | Classes | Methods | Physical lines | Duplicated windows |
|---|---:|---:|---:|---:|---:|
| Production | 80 | 80 | 343 | 7,424 | **0** |
| Tests | 37 | 64 | 447 | 7,881 | 1,059 |

## Historical v1.8.0 Inventory Summary

| Scope | PHP files | Classes | Methods | Physical lines | Duplicated windows |
|---|---:|---:|---:|---:|---:|
| Production | 57 | 57 | 282 | 6,156 | **0** |
| Tests | 29 | 44 | 398 | 6,830 | 1,014 |

The production deduplication program in v1.8.0 reduced production duplicate windows from 37 down to **0**, and test deduplication reduced duplicate windows from 1,424 down to 1,014 while extracting shared test fixtures (`JobDataFactory`, `ClaimedJobFactory`, `WorkerHarness`). These figures are retained for historical comparison only.

### Current method hotspots

| Method | Cognitive | Cyclomatic | Nesting | Lines |
|---|---:|---:|---:|---:|
| `WorkerOptions::__construct()` | 11 | 12 | 1 | 27 |
| `PdoJobStorage::createJobs()` | 9 | 10 | 1 | 61 |
| `QueueReconciler::reconcile()` | 9 | 9 | 2 | 46 |
| `InMemoryQueueDriver::recoverStaleProcessing()` | 9 | 8 | 2 | 27 |
| `InMemoryJobStorage::recoverStaleJobs()` | 9 | 6 | 2 | 33 |
| `PdoJobStorage::attemptCreateIdempotentJob()` | 8 | 7 | 2 | 42 |
| `Worker::claimNextJob()` | 8 | 7 | 2 | 38 |
| `JobDispatcher::cancelJob()` | 8 | 6 | 3 | 17 |
| `InMemoryJobStorage::recoverStaleJobsForQueue()` | 7 | 7 | 2 | 24 |
| `InMemoryQueueDriver::promoteDelayedJobs()` | 7 | 6 | 2 | 22 |

These are the ten highest cognitive-complexity methods in the current
inventory. The quality ratchet continues to enforce the thresholds for every
method, not only the displayed hotspots.

The largest production classes are `PdoJobStorage` (981 lines), `Worker`
(816), `InMemoryJobStorage` (561), and `RedisQueueDriver` (460). Class size is
reported for review and is not, by itself, a reason to split a class.

## Characterization protection

The first structural targets are `Worker::run()`/`claimNextJob()`, the PDO
transactional claim and fenced mutations, the matching in-memory claim
semantics, and `RedisQueueDriver::dequeue()`. Existing lifecycle, crash
recovery, and worker failure tests remain part of their characterization map.
Initial characterization work added these missing edge cases:

- `WorkerTest::testRunReturnsErrorWhenInitialRecoveryFails()` protects fatal
  startup recovery and the worker exit code;
- `PdoJobStorageTest::testClaimTransactionRollsBackWhenClaimUpdateFails()`
  protects rollback and durable pending state after an exceptional claim;
- both storage suites now run `testReclaimFencesThePreviousLease()` to protect
  lost-ownership writes after a same-worker reclaim;
- `RedisQueueDriverTest::testBlockingDequeueDiscardsMalformedNonScalarResponse()`
  protects malformed blocking responses and processing-state cleanup.

The existing worker tests additionally cover lost ownership before completion,
retry scheduling, and terminal failure, plus queue/storage claim exceptions.
The existing storage and Redis suites cover empty/unavailable claims, stale and
poison recovery, malformed non-blocking IDs, and bounded visibility repair.

## Static-analysis and PHPCS review

PHPStan reports unmatched ignored errors. The current suppressions are
explicitly baselined for the optional Predis boundary and legacy test mocks:

- the `Predis\ClientInterface` pattern and the one inline capability-probe
  ignore support optional/custom Predis connections;
- one PHPUnit message pattern covers dynamic assertion dispatch in tests;
- the remaining test-only identifiers cover untyped third-party mocks and old
  test helpers. They remain visible because unmatched-ignore reporting is on.

PHPCS currently warns at cyclomatic complexity 25 and nesting depth 5, with
absolute limits of 35 and 8. Tightening those global thresholds to the desired
15/3 targets would fail existing hotspots before control-flow refactoring
addressed them, so the thresholds are retained for now. The test exclusions
cover multiple declarations, file side effects, production-oriented type-hint
rules, and the characterized long-line surface; no broader exclusion was added.
The quality ratchet immediately enforces cyclomatic and cognitive complexity
15, nesting 3, method size 100, and class size 500 for new code while
preventing any baseline metric from worsening.
