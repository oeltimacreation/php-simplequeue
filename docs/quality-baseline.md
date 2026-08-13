# Quality Baseline & Inventory (v1.9.0)

This report records the quality baseline and inventory for the **v1.9.0** release. The machine-readable baseline is stored in [`quality/quality-baseline.json`](../quality/quality-baseline.json).

## Reproduce the inventory

The project uses a dependency-free analyzer built on PHP's `token_get_all()`.
Adding a third-party cognitive-complexity or copy/paste detector was rejected
for this baseline because the existing PHP quality dependencies do not provide
both measures and an additional development dependency was not justified for
an internal ratchet.

```bash
composer quality-report
composer quality-ratchet
```

## v1.9.0 Inventory

The Stage 4–5 re-baseline was written after the middleware, typed-event,
failed-job administration, benchmark, contract, fault-injection, and soak
coverage landed:

| Scope | PHP files | Classes | Methods | Physical lines | Duplicated windows |
|---|---:|---:|---:|---:|---:|
| Production | 80 | 80 | 343 | 7,424 | **0** |
| Tests | 37 | 64 | 447 | 7,881 | 1,059 |

All production methods remain within the 15/15/3 complexity and 100-line
ratchet targets, and production duplication remains at zero. The refreshed
baseline records the small internal typed-event and queue-helper changes;
no ratchet exception was added.

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

## Historical v1.8.0 Inventory Summary

| Scope | PHP files | Classes | Methods | Physical lines | Duplicated windows |
|---|---:|---:|---:|---:|---:|
| Production | 57 | 57 | 282 | 6,156 | **0** |
| Tests | 29 | 44 | 398 | 6,830 | 1,014 |

The production deduplication program in v1.8.0 reduced production duplicate windows from 37 down to **0**, and test deduplication reduced duplicate windows from 1,424 down to 1,014 while extracting shared test fixtures (`JobDataFactory`, `ClaimedJobFactory`, `WorkerHarness`). These figures are retained for historical comparison only.

### Method hotspots

| Priority | Method | Cognitive | Cyclomatic | Nesting | Lines | Risk basis |
|---|---|---:|---:|---:|---:|---|
| P0 | `PdoJobStorage::claimWithTransaction()` | 32 | 18 | 4 | 106 | Transaction cleanup, claim atomicity, and lease creation |
| P0 | `Worker::run()` | 21 | 11 | 3 | 112 | Long-running orchestration and infrastructure failure handling |
| P0 | `Worker::claimNextJob()` | 13 | 9 | 3 | 63 | Queue/storage handoff and acknowledgement decisions |
| P0 | `InMemoryQueueDriver::recoverStaleProcessing()` | 16 | 10 | 4 | 32 | Crash-recovery parity with Redis |
| P0 | `InMemoryJobStorage::claimNextAvailable()` | 13 | 10 | 2 | 34 | Reference behavior for fenced claims |
| P0 | `RedisQueueDriver::dequeue()` | 9 | 10 | 1 | 43 | External state transition and malformed backend responses |
| P1 | `QueueReconciler::reconcile()` | 16 | 15 | 2 | 55 | Bounded repair and duplicate-notification decisions |
| P1 | `PdoJobStorage::createIdempotentJob()` | 15 | 8 | 3 | 45 | Savepoint and concurrent uniqueness behavior |
| P1 | `PdoJobStorage::createJobs()` | 12 | 12 | 2 | 67 | Batch transaction and serialization rollback |
| P1 | `RedisQueueDriver::validateTimeout()` | 12 | 7 | 4 | 28 | Optional Predis connection boundary |
| P2 | `JobData::fromRaw()` | 21 | 24 | 1 | 36 | High branch count but flat, deterministic boundary normalization |

Raw score alone does not set the priority. The worker and queue/storage claim
paths are P0 because they are frequently changed and a regression can lose
ownership, duplicate delivery, or strand a notification. `JobData::fromRaw()`
has the second-highest raw scores, but its control flow is flat and its
normalization behavior is already isolated, so it was addressed during
type-safety normalization. The measurements confirm all four reviewed target classes:
`Worker`, `PdoJobStorage`, `InMemoryJobStorage`, and `RedisQueueDriver`.

The largest production classes are `PdoJobStorage` (1,115 lines), `Worker`
(771), `InMemoryJobStorage` (534), and `RedisQueueDriver` (424). Class size
supports the priority but is not, by itself, a reason to split a class.

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

PHPStan now reports unmatched ignored errors. Five stale suppressions were
removed: two obsolete Redis return-type patterns, one obsolete test cast
identifier, and two unmatched inline ignores. The active suppressions remain
explicitly baselined for the optional Predis boundary and legacy test mocks:

- the `Predis\ClientInterface` pattern and the one inline capability-probe
  ignore support optional/custom Predis connections;
- two PHPUnit message patterns cover dynamic assertion dispatch and the legacy
  `isType()` surface in tests;
- the remaining test-only identifiers cover untyped third-party mocks and old
  test helpers. They remain visible because unmatched-ignore reporting is on.

PHPCS currently warns at cyclomatic complexity 25 and nesting depth 5, with
absolute limits of 35 and 8. Tightening those global thresholds to the desired
15/3 targets would fail existing hotspots before control-flow refactoring addressed them, so the
thresholds are retained for now. The quality ratchet immediately enforces
cyclomatic and cognitive complexity 15, nesting 3, method size 100, and class
size 500 for new code while preventing any baseline metric from worsening.
