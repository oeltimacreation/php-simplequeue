<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests;

use PDO;

final class DbHelper
{
    /**
     * Create the background_jobs schema on the given PDO connection.
     *
     */
    public static function createSchema(PDO $pdo, string $tableName = 'background_jobs'): void
    {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS {$tableName} (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    queue TEXT NOT NULL DEFAULT 'default',
                    type TEXT NOT NULL,
                    status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'running', 'completed', 'failed', 'cancelled')),
                    payload TEXT,
                    attempts INTEGER NOT NULL DEFAULT 0,
                    max_attempts INTEGER NOT NULL DEFAULT 3,
                    progress INTEGER DEFAULT NULL,
                    progress_message TEXT DEFAULT NULL,
                    result TEXT DEFAULT NULL,
                    available_at TEXT NOT NULL,
                    started_at TEXT DEFAULT NULL,
                    completed_at TEXT DEFAULT NULL,
                    locked_by TEXT DEFAULT NULL,
                    locked_at TEXT DEFAULT NULL,
                    lease_token TEXT DEFAULT NULL,
                    error_message TEXT DEFAULT NULL,
                    error_trace TEXT DEFAULT NULL,
                    request_id TEXT DEFAULT NULL,
                    created_at TEXT NOT NULL,
                    updated_at TEXT NOT NULL
                )
            ");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_queue_status_{$tableName} ON {$tableName} (queue, status)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_claim_ready_{$tableName} ON {$tableName} (queue, status, available_at, id)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_status_available_{$tableName} ON {$tableName} (status, available_at)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_locked_at_{$tableName} ON {$tableName} (locked_at)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_lease_token_{$tableName} ON {$tableName} (lease_token)");
            $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS uq_active_request_id_{$tableName} ON {$tableName} (request_id) WHERE status IN ('pending', 'running')");
            return;
        }

        if ($driver === 'pgsql') {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS {$tableName} (
                    id BIGSERIAL PRIMARY KEY,
                    queue VARCHAR(255) NOT NULL DEFAULT 'default',
                    type VARCHAR(255) NOT NULL,
                    status VARCHAR(20) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'running', 'completed', 'failed', 'cancelled')),
                    payload JSONB,
                    attempts INTEGER NOT NULL DEFAULT 0,
                    max_attempts INTEGER NOT NULL DEFAULT 3,
                    progress INTEGER DEFAULT NULL,
                    progress_message VARCHAR(255) DEFAULT NULL,
                    result JSONB DEFAULT NULL,
                    available_at TIMESTAMP NOT NULL,
                    started_at TIMESTAMP DEFAULT NULL,
                    completed_at TIMESTAMP DEFAULT NULL,
                    locked_by VARCHAR(255) DEFAULT NULL,
                    locked_at TIMESTAMP DEFAULT NULL,
                    lease_token VARCHAR(64) DEFAULT NULL,
                    error_message TEXT DEFAULT NULL,
                    error_trace TEXT DEFAULT NULL,
                    request_id VARCHAR(255) DEFAULT NULL,
                    created_at TIMESTAMP NOT NULL,
                    updated_at TIMESTAMP NOT NULL
                )
            ");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_queue_status_{$tableName} ON {$tableName} (queue, status)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_claim_ready_{$tableName} ON {$tableName} (queue, status, available_at, id)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_status_available_{$tableName} ON {$tableName} (status, available_at)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_locked_at_{$tableName} ON {$tableName} (locked_at)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_lease_token_{$tableName} ON {$tableName} (lease_token)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_type_{$tableName} ON {$tableName} (type)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_request_id_{$tableName} ON {$tableName} (request_id)");
            $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS uq_active_request_id_{$tableName} ON {$tableName} (request_id) WHERE status IN ('pending', 'running')");
            return;
        }

        if ($driver === 'mysql') {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS {$tableName} (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            return;
        }

        throw new \RuntimeException(\sprintf('Unsupported database driver: %s', $driver));
    }
}
