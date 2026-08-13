# Runnable Examples & Sample Catalogue

This directory contains self-contained code examples demonstrating the capabilities of PHP SimpleQueue across in-memory setups, scheduled dispatching, database persistence, Redis drivers, and benchmarks.

---

## 1. In-Memory Quickstart

- **File**: [`basic/in-memory.php`](basic/in-memory.php)
- **Requirements**: PHP 8.2+ and Composer autoloader
- **Use Case**: Fast local development, prototyping, and testing without configuring external services.

### Execution Command

```bash
php examples/basic/in-memory.php
```

### Expected Output

```
Job #1: Hello, queue!
Status: completed; result: {"message":"Hello, queue!"}
```

---

## 2. Middleware and Execution Context

- **File**: [`basic/middleware.php`](basic/middleware.php)
- **Requirements**: PHP 8.2+ and Composer autoloader
- **Use Case**: Wrapping handler execution with before/after logic and reading typed job context values.

### Execution Command

```bash
php examples/basic/middleware.php
```

### Expected Output

```
Before message.print job #1 (attempt 1)
Handler processed job #1: Hello through middleware!
After job #1: <duration> ms
Status: completed
```

The duration is runtime-dependent.

Middleware is a worker-layer feature and does not add storage or queue-driver
operations. The same registration and execution order applies to the Redis,
database, and in-memory backends.

---

## 3. Failed-Job Administration

- **File**: [`basic/failed-job-admin.php`](basic/failed-job-admin.php)
- **Requirements**: PHP 8.2+ and Composer autoloader
- **Use Case**: Inspect a terminal failure and re-queue it through the additive
  `AdminManager` API.

### Execution Command

```bash
php examples/basic/failed-job-admin.php
```

### Expected Output

```
Failed jobs: 1
Re-queued job #1: pending
```

---

## 4. Scheduled Dispatching

- **File**: [`basic/scheduled-dispatch.php`](basic/scheduled-dispatch.php)
- **Requirements**: PHP 8.2+ and Composer autoloader
- **Use Case**: Delaying job execution into the future via relative delays (`dispatchAfter()`) or absolute timestamps (`dispatchAt()`).

### Execution Command

```bash
php examples/basic/scheduled-dispatch.php
```

### Expected Output

```
Dispatched job #1; first availability in 2s.
Immediate processOne(): processed=false, status=pending (not claimable yet)
Waiting 2s for the job to become due...
Job #1: Hello from the future!
Due processOne(): processed=true, status=completed, result={"message":"Hello from the future!"}
Dispatched job #2 via dispatchAt() with a 1s absolute timestamp.
Job #2: Absolute timestamp!
dispatchAt() result status: completed
```

---

## 5. Production Redis & PDO Database Example

- **Directory**: [`redis/`](redis/README.md)
- **Requirements**: PDO (MySQL / PostgreSQL / SQLite), Redis 7+ or Valkey 8+, `predis/predis:^3`
- **Use Case**: High-performance production setup combining authoritative database persistence with Redis delivery notifications.

### Setup & Run

Refer to [`redis/README.md`](redis/README.md) for environment configuration.

```bash
# Terminal 1: Start background worker
php examples/redis/worker.php

# Terminal 2: Dispatch background jobs
php examples/redis/dispatch.php
```

---

## 6. SQLite Database Benchmark

- **File**: [`benchmark/database.php`](benchmark/database.php)
- **Requirements**: PHP 8.2+ with `pdo_sqlite`
- **Use Case**: Benchmarking batch insert, claim, processing, and completion throughput on SQLite.

### Execution Command

```bash
php examples/benchmark/database.php 1000
```

---

## 7. Migrations Catalogue

- **File**: [`migrations/1.3.0-lease-based-claims.sql`](migrations/1.3.0-lease-based-claims.sql)
- **Requirements**: Existing database installation upgrading from v1.2.x
- **Use Case**: Schema migration required when upgrading to lease-based claims (introduced in v1.3.0).

---

> **Note**: Do not use in-memory drivers or storage backends (`InMemoryJobStorage`, `InMemoryQueueDriver`) for persistent production jobs. Their state is ephemeral and lives only for the lifetime of the active PHP process. For persistent workloads, use `PdoJobStorage` with database schemas from [docs/database.md](../docs/database.md).
