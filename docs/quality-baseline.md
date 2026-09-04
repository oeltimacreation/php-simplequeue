# Quality Gates (v1.11)

This document records the maintained, enforceable quality gates. Bespoke
cognitive-complexity and overlapping token-fingerprint tooling was retired in
v1.11 and is not replaced with another custom parser.

## Gates

- **PHPStan level 9** (`phpstan.neon`, `phpVersion 80200`) over `src` and
  `tests` with strict, deprecation, and PHPUnit rules. No broad new
  suppressions; deprecations use precise file/message suppressions.
- **PHPCS** (`phpcs.xml.dist`, PSR-12 + Slevomat) over `src` and `tests`.
  Executable/fixture paths use narrow structural exclusions only where needed.
- **Coverage**: lines `83.9` and methods `71.2` from Clover via
  `scripts/check-coverage.php` (v1.10 measured 83.91% lines, 71.23% methods).
- **Mutation**: Infection `^0.31.9` focused on Worker, both storage
  implementations, Redis driver, and reconciler. The frozen baseline score is
  the floor; ownership/transition mutants must be killed.
- **API comparison**: isolated Roave `8.21.0` vs `v1.10.0` reports no break
  (`scripts/bc-check.sh`, `.github/workflows/bc-check.yml`).
- **Operation budgets**: `benchmarks/operation-count-checks.php` asserts exact
  and maximum statement, transaction, Redis command, roundtrip, event, and
  allocation budgets in relevant CI lanes.

## Reproduce

```bash
composer check-fast
composer check
composer check-ci
composer budgets
composer mutation
```
