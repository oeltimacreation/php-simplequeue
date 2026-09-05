# Quality Gates (v1.11)

This document records the maintained, enforceable quality gates. Bespoke
cognitive-complexity and overlapping token-fingerprint tooling was retired in
v1.11 and is not replaced with another custom parser.

## Gates

- **PHPStan level 9** (`phpstan.neon`, `phpVersion 80200`) over `src`, `tests`,
  `benchmarks`, `scripts`, and executable `examples`, with strict,
  deprecation, and PHPUnit rules. No broad new
  suppressions; deprecations use precise file/message suppressions.
- **PHPCS** (`phpcs.xml.dist`, PSR-12 + Slevomat) over the same PHP paths.
  Executable/fixture paths use narrow structural exclusions only where needed;
  cyclomatic warning/error limits are 15/25, nesting is 3/5, functions are at
  most 100 lines, and classes at most 500 lines.
- **Coverage**: lines `83.9` and methods `71.2` from Clover via
  `scripts/check-coverage.php` (v1.10 measured 83.91% lines, 71.23% methods).
- **Mutation**: Infection `^0.31.9` focused on Worker, both storage
  implementations, Redis driver, and reconciler. The frozen baseline score is
  the floor; ownership/transition mutants must be killed.
- **API comparison**: isolated Roave `8.21.0` vs `v1.10.0` reports no break
  (`scripts/bc-check.sh`, `.github/workflows/bc-check.yml`).
- **Operation budgets**: `benchmarks/operation-count-checks.php` asserts exact
  and maximum statement, transaction, Redis command, roundtrip, event, and
  notifier-operation budgets in relevant CI lanes. Each definition records the
  mechanism, scaling formula, and rationale.

The frozen v1.10 measurements are stored in
`quality/v1.10-baseline.json` and `quality/mutation-baseline.json`. The v1.11
four-scale benchmark/counter evidence is stored in
`quality/v1.11-release-profile.json`; wall-clock values are informative and are
not shared-runner pass/fail thresholds.

## Reproduce

```bash
composer check-fast
composer check
composer check-ci
composer test-random
composer budgets
composer benchmark-profile
composer mutation
bash scripts/bc-check.sh v1.10.0
```
