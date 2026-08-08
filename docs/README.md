# SimpleQueue Documentation Index

Welcome to the PHP SimpleQueue documentation. Select your role or goal below for guided reading paths and complete reference materials.

## For Users & Operators

If you are setting up, configuring, or running SimpleQueue in an application:

1. **[Getting Started](getting-started.md)** — Core concepts, initial setup, durable database persistence, and your first worker process. *(Start here)*
2. **[Database Guide](database.md)** — Schema definitions (MySQL, PostgreSQL, SQLite), index strategies, transaction boundaries, and idempotency guarantees.
3. **[Configuration](configuration.md)** — Worker options, polling frequency, connection pooling, signal handling, and driver selection (`auto`, `redis`, `db`).
4. **[Operations Guide](operations.md)** — Deployment topology, worker supervision, queue maintenance/repair (`QueueReconciler`), job retention, and telemetry.

---

## For Integrators & Application Authors

If you are embedding SimpleQueue, upgrading from prior releases, or building custom storage/drivers:

1. **[Upgrading Guide](upgrading.md)** — Upgrading instructions and backward-compatibility notes across versions (including v1.7.x and v1.8.x).
2. **[Extending SimpleQueue](extending.md)** — Implementing custom `JobHandlerInterface`, custom `JobStorageInterface`, or custom `QueueDriverInterface`.
3. **[Backend Parity Map](backend-parity.md)** — Feature-by-feature matrix comparing Redis driver, Database driver, and In-Memory driver behavior.
4. **[Failure & Recovery Matrix](failure-matrix.md)** — Edge-case analysis of worker crashes, network partitions, database deadlocks, and stale lease recovery.

---

## For Maintainers & Contributors

If you are contributing to PHP SimpleQueue, reviewing architecture, or examining code quality baselines:

1. **[Architecture Specification](architecture.md)** — High-level architecture, two-layer storage/driver model, job lifecycle state machine, lease fencing, scheduled dispatch, and hydration boundaries.
2. **[Type-Safety Specifications](type-safety.md)** — PHP 8.2+ type compliance, array shapes (`JobDefinitionShape`, `StorageRowShape`), immutable value objects, and serialization rules.
3. **[Quality Baseline](quality-baseline.md)** — Repository code quality metrics, method/class size limits, cognitive/cyclomatic complexity bounds, and the quality ratchet system.
4. **[Performance Profile](performance.md)** — Benchmark scenarios, throughput baseline, payload wire byte measurements, and PDO/Redis roundtrip counts.
5. **[Maintainer Notes](maintainer-notes.md)** — Release procedures, Git tag vs. Composer version rules, Packagist publishing workflows, and verification commands.

---

## Runnable Examples

Complete, runnable code examples live in [examples/](../examples/README.md).


