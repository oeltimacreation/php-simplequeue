# Database guide

Job storage is authoritative for payloads, status, leases, retries, and
results. Apply one fresh schema below before starting a durable worker.

## MySQL / MariaDB

```sql
CREATE TABLE background_jobs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    queue VARCHAR(255) NOT NULL DEFAULT 'default',
    type VARCHAR(255) NOT NULL,
    status ENUM('pending', 'running', 'completed', 'failed', 'cancelled') NOT NULL DEFAULT 'pending',
    payload JSON,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts INT UNSIGNED NOT NULL DEFAULT 3,
    progress INT UNSIGNED DEFAULT NULL,
    progress_message VARCHAR(255) DEFAULT NULL,
    result JSON DEFAULT NULL,
    available_at DATETIME NOT NULL,
    started_at DATETIME DEFAULT NULL,
    completed_at DATETIME DEFAULT NULL,
    locked_by VARCHAR(255) DEFAULT NULL,
    locked_at DATETIME DEFAULT NULL,
    lease_token VARCHAR(64) DEFAULT NULL,
    error_message TEXT DEFAULT NULL,
    error_trace TEXT DEFAULT NULL,
    request_id VARCHAR(255) DEFAULT NULL,
    active_request_id VARCHAR(255) GENERATED ALWAYS AS (
        CASE WHEN status IN ('pending', 'running') THEN request_id ELSE NULL END
    ) VIRTUAL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_queue_status (queue, status),
    INDEX idx_claim_ready (queue, status, available_at, id),
    INDEX idx_status_available (status, available_at),
    INDEX idx_locked_at (locked_at),
    INDEX idx_lease_token (lease_token),
    INDEX idx_type (type),
    INDEX idx_request_id (request_id),
    UNIQUE KEY uq_active_request_id (active_request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

## PostgreSQL

```sql
CREATE TABLE background_jobs (
    id BIGSERIAL PRIMARY KEY,
    queue VARCHAR(255) NOT NULL DEFAULT 'default', type VARCHAR(255) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending'
        CHECK (status IN ('pending', 'running', 'completed', 'failed', 'cancelled')),
    payload JSONB, attempts INTEGER NOT NULL DEFAULT 0, max_attempts INTEGER NOT NULL DEFAULT 3,
    progress INTEGER, progress_message VARCHAR(255), result JSONB,
    available_at TIMESTAMP NOT NULL, started_at TIMESTAMP, completed_at TIMESTAMP,
    locked_by VARCHAR(255), locked_at TIMESTAMP, lease_token VARCHAR(64),
    error_message TEXT, error_trace TEXT, request_id VARCHAR(255),
    created_at TIMESTAMP NOT NULL, updated_at TIMESTAMP NOT NULL
);
CREATE INDEX idx_queue_status ON background_jobs (queue, status);
CREATE INDEX idx_claim_ready ON background_jobs (queue, status, available_at, id);
CREATE INDEX idx_status_available ON background_jobs (status, available_at);
CREATE INDEX idx_locked_at ON background_jobs (locked_at);
CREATE INDEX idx_lease_token ON background_jobs (lease_token);
CREATE INDEX idx_type ON background_jobs (type);
CREATE INDEX idx_request_id ON background_jobs (request_id);
CREATE UNIQUE INDEX uq_active_request_id ON background_jobs (request_id)
    WHERE status IN ('pending', 'running');
```

## SQLite

```sql
CREATE TABLE background_jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    queue TEXT NOT NULL DEFAULT 'default', type TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending'
        CHECK (status IN ('pending', 'running', 'completed', 'failed', 'cancelled')),
    payload TEXT, attempts INTEGER NOT NULL DEFAULT 0, max_attempts INTEGER NOT NULL DEFAULT 3,
    progress INTEGER, progress_message TEXT, result TEXT, available_at TEXT NOT NULL,
    started_at TEXT, completed_at TEXT, locked_by TEXT, locked_at TEXT, lease_token TEXT,
    error_message TEXT, error_trace TEXT, request_id TEXT,
    created_at TEXT NOT NULL, updated_at TEXT NOT NULL
);
CREATE INDEX idx_queue_status ON background_jobs (queue, status);
CREATE INDEX idx_claim_ready ON background_jobs (queue, status, available_at, id);
CREATE INDEX idx_status_available ON background_jobs (status, available_at);
CREATE INDEX idx_locked_at ON background_jobs (locked_at);
CREATE INDEX idx_lease_token ON background_jobs (lease_token);
CREATE UNIQUE INDEX uq_active_request_id ON background_jobs (request_id)
    WHERE status IN ('pending', 'running');
```

## Idempotent dispatch

`dispatchIdempotent()` returns a single active job for a request ID only when
the storage enforces it. The generated MySQL column and partial PostgreSQL /
SQLite indexes above are required for safe concurrent dispatch. Terminal jobs
are deliberately excluded, so a later request may create a new job.

## Existing v1.2 installations

Apply [the v1.3 lease migration](../examples/migrations/1.3.0-lease-based-claims.sql)
before upgrading. It adds `available_at`, lease fields, and required indexes.

## v1.11 durability notes

- **Strict hydration**: built-in storage reads use a strict hydrator requiring
  all persisted fields with enum/counter/nullability invariants; corrupt rows
  raise `QueueException` with job ID and field. Public `JobData::fromRaw()`
  stays permissive for BC.
- **Validation**: table names must be one or two dot-separated identifiers;
  queue/type/request/worker IDs and progress messages are capped at 255 bytes;
  IDs, limits, TTLs, and timestamps are validated identically in PDO and
  memory. Job definitions and payload JSON are validated/encoded before any
  row mutation or ID consumption.
- **Connection factory vs direct PDO**: prefer `fn() => new PDO(...)` for
  workers. Reads retry once only with a factory outside caller transactions;
  mutations never replay after a statement/commit attempt and raise
  `IndeterminateStorageOutcomeException` on uncertain commit. `reconnect()`
  rejects direct-PDO instances. Claims are rejected inside caller-owned
  transactions on every driver; ordinary CRUD may participate via
  storage-owned transactions or savepoints.
- **Batch chunking and exact IDs**: SQLite 100 rows, MySQL/PostgreSQL 1,000
  rows, plus 1 MiB splits; one transaction/savepoint across chunks.
  PostgreSQL/modern SQLite use `INSERT ... RETURNING id`; older SQLite
  inserts row-by-row in the same transaction; MySQL derives from the first ID
  plus session `auto_increment_increment` with validation.
- **Migration**: apply `examples/migrations/1.3.0-lease-based-claims.sql`
  (v1.3.0, not v1.4).
