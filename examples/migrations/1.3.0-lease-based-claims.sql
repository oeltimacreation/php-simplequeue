-- Migration for PHP SimpleQueue 1.3.0 lease-based claims.
--
-- Review table names and index names before applying to production.

-- MySQL / MariaDB
UPDATE background_jobs
SET available_at = created_at
WHERE available_at IS NULL;

ALTER TABLE background_jobs
    ADD COLUMN lease_token VARCHAR(64) DEFAULT NULL AFTER locked_at,
    MODIFY available_at DATETIME NOT NULL;

CREATE INDEX idx_claim_ready ON background_jobs (queue, status, available_at, id);
CREATE INDEX idx_lease_token ON background_jobs (lease_token);

-- PostgreSQL
-- ALTER TABLE background_jobs ADD COLUMN lease_token VARCHAR(64) DEFAULT NULL;
-- UPDATE background_jobs SET available_at = created_at WHERE available_at IS NULL;
-- ALTER TABLE background_jobs ALTER COLUMN available_at SET NOT NULL;
-- CREATE INDEX idx_claim_ready ON background_jobs (queue, status, available_at, id);
-- CREATE INDEX idx_lease_token ON background_jobs (lease_token);

-- SQLite
-- PRAGMA foreign_keys = OFF;
-- BEGIN TRANSACTION;
-- ALTER TABLE background_jobs RENAME TO background_jobs_old;
-- CREATE TABLE background_jobs (
--     id INTEGER PRIMARY KEY AUTOINCREMENT,
--     queue TEXT NOT NULL DEFAULT 'default',
--     type TEXT NOT NULL,
--     status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'running', 'completed', 'failed', 'cancelled')),
--     payload TEXT,
--     attempts INTEGER NOT NULL DEFAULT 0,
--     max_attempts INTEGER NOT NULL DEFAULT 3,
--     progress INTEGER DEFAULT NULL,
--     progress_message TEXT DEFAULT NULL,
--     result TEXT DEFAULT NULL,
--     available_at TEXT NOT NULL,
--     started_at TEXT DEFAULT NULL,
--     completed_at TEXT DEFAULT NULL,
--     locked_by TEXT DEFAULT NULL,
--     locked_at TEXT DEFAULT NULL,
--     lease_token TEXT DEFAULT NULL,
--     error_message TEXT DEFAULT NULL,
--     error_trace TEXT DEFAULT NULL,
--     request_id TEXT DEFAULT NULL,
--     created_at TEXT NOT NULL,
--     updated_at TEXT NOT NULL
-- );
-- INSERT INTO background_jobs (
--     id, queue, type, status, payload, attempts, max_attempts,
--     progress, progress_message, result, available_at, started_at,
--     completed_at, locked_by, locked_at, lease_token, error_message,
--     error_trace, request_id, created_at, updated_at
-- )
-- SELECT
--     id, queue, type, status, payload, attempts, max_attempts,
--     progress, progress_message, result, COALESCE(available_at, created_at), started_at,
--     completed_at, locked_by, locked_at, NULL, error_message,
--     error_trace, request_id, created_at, updated_at
-- FROM background_jobs_old;
-- DROP TABLE background_jobs_old;
-- CREATE INDEX idx_queue_status ON background_jobs (queue, status);
-- CREATE INDEX idx_claim_ready ON background_jobs (queue, status, available_at, id);
-- CREATE INDEX idx_status_available ON background_jobs (status, available_at);
-- CREATE INDEX idx_locked_at ON background_jobs (locked_at);
-- CREATE INDEX idx_lease_token ON background_jobs (lease_token);
-- CREATE INDEX idx_type ON background_jobs (type);
-- CREATE INDEX idx_request_id ON background_jobs (request_id);
-- COMMIT;
-- PRAGMA foreign_keys = ON;
