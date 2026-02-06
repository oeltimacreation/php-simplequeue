<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Storage;

use Oeltima\SimpleQueue\Contract\JobData;
use Oeltima\SimpleQueue\Contract\JobStorageAdminInterface;
use Oeltima\SimpleQueue\Contract\JobStorageInterface;
use PDO;

/**
 * PDO-based job storage implementation.
 *
 * Provides a database-agnostic implementation using PDO.
 * Works with MySQL, PostgreSQL, SQLite, and other PDO-supported databases.
 *
 * Supports auto-reconnect for long-running workers via connection factory.
 */
class PdoJobStorage implements JobStorageInterface, JobStorageAdminInterface
{
    protected ?PDO $pdo = null;

    /** @var callable|null Factory function to create PDO connection: fn(): PDO */
    protected $connectionFactory = null;

    protected string $table;
    protected string $dateFormat = 'Y-m-d H:i:s';

    /**
     * @param PDO|callable $connection PDO instance or factory callable (fn(): PDO)
     * @param string $table Table name for jobs (default: 'background_jobs')
     */
    public function __construct(PDO|callable $connection, string $table = 'background_jobs')
    {
        if ($connection instanceof PDO) {
            $this->pdo = $connection;
        } else {
            $this->connectionFactory = $connection;
        }
        $this->table = $table;
    }

    /**
     * Get PDO connection, reconnecting if necessary.
     *
     * @throws \RuntimeException If connection cannot be established
     */
    protected function getPdo(): PDO
    {
        if ($this->pdo !== null && $this->isConnectionAlive()) {
            return $this->pdo;
        }

        if ($this->connectionFactory !== null) {
            $this->pdo = ($this->connectionFactory)();
            return $this->pdo;
        }

        if ($this->pdo === null) {
            throw new \RuntimeException('PDO connection is not available and no factory provided');
        }

        return $this->pdo;
    }

    /**
     * Check if the current PDO connection is still alive.
     */
    protected function isConnectionAlive(): bool
    {
        if ($this->pdo === null) {
            return false;
        }

        try {
            $this->pdo->query('SELECT 1');
            return true;
        } catch (\PDOException) {
            $this->pdo = null;
            return false;
        }
    }

    /**
     * Force reconnection on next database operation.
     */
    public function reconnect(): void
    {
        $this->pdo = null;
    }

    public function createJob(
        string $type,
        array $payload,
        string $queue = 'default',
        int $maxAttempts = 3,
        ?string $requestId = null
    ): int {
        $now = $this->now();

        $sql = "INSERT INTO {$this->table} (
            queue, type, status, payload, attempts, max_attempts,
            available_at, started_at, completed_at, locked_by, locked_at,
            error_message, error_trace, request_id, created_at, updated_at
        ) VALUES (
            :queue, :type, 'pending', :payload, 0, :max_attempts,
            NULL, NULL, NULL, NULL, NULL,
            NULL, NULL, :request_id, :created_at, :updated_at
        )";

        $pdo = $this->getPdo();
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'queue' => $queue,
            'type' => $type,
            'payload' => json_encode($payload),
            'max_attempts' => $maxAttempts,
            'request_id' => $requestId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $pdo->lastInsertId();
    }

    public function find(int $id): ?JobData
    {
        $sql = "SELECT * FROM {$this->table} WHERE id = :id";
        $stmt = $this->getPdo()->prepare($sql);
        $stmt->execute(['id' => $id]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return JobData::fromRaw($row);
    }

    public function findActiveByRequestId(string $requestId): ?JobData
    {
        $sql = "SELECT * FROM {$this->table}
            WHERE request_id = :request_id
            AND status IN ('pending', 'running')
            LIMIT 1";

        $stmt = $this->getPdo()->prepare($sql);
        $stmt->execute(['request_id' => $requestId]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return JobData::fromRaw($row);
    }

    public function getNextPendingJobId(string $queue = 'default'): ?int
    {
        $now = $this->now();

        $sql = "SELECT id FROM {$this->table}
            WHERE status = 'pending'
            AND queue = :queue
            AND (available_at IS NULL OR available_at <= :now)
            ORDER BY id ASC
            LIMIT 1";

        $stmt = $this->getPdo()->prepare($sql);
        $stmt->execute(['queue' => $queue, 'now' => $now]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false || empty($row['id'])) {
            return null;
        }

        return (int) $row['id'];
    }

    public function claimJob(int $id, string $workerId): bool
    {
        $now = $this->now();

        $sql = "UPDATE {$this->table}
            SET status = 'running',
                locked_by = :worker_id,
                locked_at = :locked_at,
                started_at = :started_at,
                updated_at = :updated_at
            WHERE id = :id
            AND status = 'pending'
            AND (available_at IS NULL OR available_at <= :now)";

        $stmt = $this->getPdo()->prepare($sql);
        $stmt->execute([
            'id' => $id,
            'worker_id' => $workerId,
            'locked_at' => $now,
            'started_at' => $now,
            'updated_at' => $now,
            'now' => $now,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function markCompleted(int $id, mixed $result = null): bool
    {
        $now = $this->now();

        $sql = "UPDATE {$this->table}
            SET status = 'completed',
                result = :result,
                completed_at = :completed_at,
                updated_at = :updated_at
            WHERE id = :id";

        $stmt = $this->getPdo()->prepare($sql);
        $stmt->execute([
            'id' => $id,
            'result' => $result === null ? null : json_encode($result),
            'completed_at' => $now,
            'updated_at' => $now,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function markFailed(int $id, string $errorMessage, ?string $errorTrace = null): bool
    {
        $now = $this->now();

        $sql = "UPDATE {$this->table}
            SET status = 'failed',
                error_message = :error_message,
                error_trace = :error_trace,
                completed_at = :completed_at,
                updated_at = :updated_at
            WHERE id = :id";

        $stmt = $this->getPdo()->prepare($sql);
        $stmt->execute([
            'id' => $id,
            'error_message' => $errorMessage,
            'error_trace' => $errorTrace,
            'completed_at' => $now,
            'updated_at' => $now,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function updateProgress(int $id, ?int $progress = null, ?string $message = null): bool
    {
        $now = $this->now();

        $sql = "UPDATE {$this->table}
            SET progress = :progress,
                progress_message = :message,
                updated_at = :updated_at
            WHERE id = :id";

        $stmt = $this->getPdo()->prepare($sql);
        $stmt->execute([
            'id' => $id,
            'progress' => $progress,
            'message' => $message,
            'updated_at' => $now,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function scheduleRetry(int $id, int $attempts, int $delaySeconds, ?string $errorMessage = null): bool
    {
        $now = $this->now();
        $availableAt = date($this->dateFormat, strtotime($now) + $delaySeconds);

        $sql = "UPDATE {$this->table}
            SET status = 'pending',
                attempts = :attempts,
                available_at = :available_at,
                error_message = :error_message,
                locked_by = NULL,
                locked_at = NULL,
                updated_at = :updated_at
            WHERE id = :id";

        $stmt = $this->getPdo()->prepare($sql);
        $stmt->execute([
            'id' => $id,
            'attempts' => $attempts,
            'available_at' => $availableAt,
            'error_message' => $errorMessage,
            'updated_at' => $now,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function recoverStaleJobs(int $ttlSeconds): int
    {
        $now = $this->now();
        $staleThreshold = date($this->dateFormat, strtotime($now) - $ttlSeconds);

        $sql = "UPDATE {$this->table}
            SET status = 'pending',
                locked_by = NULL,
                locked_at = NULL,
                available_at = NULL,
                updated_at = :updated_at
            WHERE status = 'running'
            AND locked_at < :stale_threshold";

        $stmt = $this->getPdo()->prepare($sql);
        $stmt->execute([
            'stale_threshold' => $staleThreshold,
            'updated_at' => $now,
        ]);

        return $stmt->rowCount();
    }

    /**
     * Get jobs by status.
     *
     * @param string|null $status Filter by status (null for all)
     * @param string|null $queue Filter by queue (null for all)
     * @param int $limit Maximum number of jobs to return
     * @param int $offset Offset for pagination
     * @return JobData[]
     */
    public function list(?string $status = null, ?string $queue = null, int $limit = 100, int $offset = 0): array
    {
        $sql = "SELECT * FROM {$this->table} WHERE 1=1";
        $params = [];

        if ($status !== null) {
            $sql .= " AND status = :status";
            $params['status'] = $status;
        }

        if ($queue !== null) {
            $sql .= " AND queue = :queue";
            $params['queue'] = $queue;
        }

        $sql .= " ORDER BY id DESC LIMIT :limit OFFSET :offset";

        $stmt = $this->getPdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $jobs = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $jobs[] = JobData::fromRaw($row);
        }

        return $jobs;
    }

    /**
     * Count jobs by status.
     *
     * @param string|null $status Filter by status (null for all)
     * @param string|null $queue Filter by queue (null for all)
     * @return int
     */
    public function count(?string $status = null, ?string $queue = null): int
    {
        $sql = "SELECT COUNT(*) as cnt FROM {$this->table} WHERE 1=1";
        $params = [];

        if ($status !== null) {
            $sql .= " AND status = :status";
            $params['status'] = $status;
        }

        if ($queue !== null) {
            $sql .= " AND queue = :queue";
            $params['queue'] = $queue;
        }

        $stmt = $this->getPdo()->prepare($sql);
        $stmt->execute($params);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int) ($row['cnt'] ?? 0);
    }

    /**
     * Delete completed jobs older than a given number of days.
     *
     * @param int $days Number of days to keep completed jobs
     * @return int Number of deleted jobs
     */
    public function pruneCompleted(int $days = 7): int
    {
        $threshold = date($this->dateFormat, strtotime("-{$days} days"));

        $sql = "DELETE FROM {$this->table}
            WHERE status IN ('completed', 'cancelled')
            AND completed_at < :threshold";

        $stmt = $this->getPdo()->prepare($sql);
        $stmt->execute(['threshold' => $threshold]);

        return $stmt->rowCount();
    }

    protected function now(): string
    {
        return date($this->dateFormat);
    }
}
