<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Storage;

use Oeltima\SimpleQueue\Contract\ClockInterface;
use Oeltima\SimpleQueue\Contract\ClaimedJob;
use Oeltima\SimpleQueue\Contract\JobData;
use Oeltima\SimpleQueue\Contract\JobStatus;
use Oeltima\SimpleQueue\Contract\JobStorageAdminInterface;
use Oeltima\SimpleQueue\Contract\JobStorageInterface;
use Oeltima\SimpleQueue\SystemClock;
use PDO;
use PDOException;
use PDOStatement;

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

    /** @var callable(): PDO|null Factory function to create PDO connection */
    protected $connectionFactory = null;

    protected string $table;
    protected string $dateFormat = 'Y-m-d H:i:s';

    /**
     * @param PDO|callable $connection PDO instance or factory callable (fn(): PDO)
     * @param string $table Table name for jobs (default: 'background_jobs')
     * @param ClockInterface|null $clock Clock implementation
     */
    public function __construct(
        #[\SensitiveParameter] PDO|callable $connection,
        string $table = 'background_jobs',
        private readonly ?ClockInterface $clock = null
    ) {
        if ($connection instanceof PDO) {
            $this->pdo = $this->configurePdo($connection);
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
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        if ($this->connectionFactory !== null) {
            $this->pdo = $this->configurePdo(($this->connectionFactory)());
            return $this->pdo;
        }

        throw new \RuntimeException('PDO connection is not available and no factory provided');
    }

    /**
     * Force reconnection on next database operation.
     */
    public function reconnect(): void
    {
        $this->pdo = null;
    }

    /**
     * Run a database operation, retrying once after a connection-loss exception.
     *
     * @template T
     * @param callable(PDO): T $operation Database operation
     * @return T Operation result
     */
    protected function withReconnect(callable $operation): mixed
    {
        try {
            return $operation($this->getPdo());
        } catch (PDOException $e) {
            if ($this->connectionFactory === null || !$this->isConnectionException($e)) {
                throw $e;
            }

            $this->pdo = null;
            return $operation($this->getPdo());
        }
    }

    /**
     * Prepare and execute a SQL statement with reconnect support.
     *
     * @param string $sql SQL statement
     * @param array<string, mixed> $params Bound parameters
     * @return PDOStatement Executed statement
     */
    protected function execute(string $sql, array $params = []): PDOStatement
    {
        return $this->withReconnect(function (PDO $pdo) use ($sql, $params): PDOStatement {
            $stmt = $pdo->prepare($sql);
            if (!$stmt instanceof PDOStatement) {
                throw new \RuntimeException('Failed to prepare SQL statement');
            }

            $stmt->execute($params);
            return $stmt;
        });
    }

    /**
     * Check whether a PDO exception likely represents a lost connection.
     *
     * @param PDOException $e PDO exception
     * @return bool True for connection-loss errors
     */
    protected function isConnectionException(PDOException $e): bool
    {
        $message = strtolower($e->getMessage());
        $code = (string) $e->getCode();
        $errorInfoCode = isset($e->errorInfo[1]) ? (string) $e->errorInfo[1] : '';

        if (in_array($code, ['2006', '2013', '08003', '08006'], true)) {
            return true;
        }

        if (in_array($errorInfoCode, ['2006', '2013'], true)) {
            return true;
        }

        foreach (['server has gone away', 'lost connection', 'connection refused', 'connection is closed'] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Configure a PDO connection for reliable error handling.
     *
     * @param PDO $pdo PDO connection
     * @return PDO Configured PDO connection
     */
    protected function configurePdo(PDO $pdo): PDO
    {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return $pdo;
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
            available_at, started_at, completed_at, locked_by, locked_at, lease_token,
            error_message, error_trace, request_id, created_at, updated_at
        ) VALUES (
            :queue, :type, 'pending', :payload, 0, :max_attempts,
            :available_at, NULL, NULL, NULL, NULL, NULL,
            NULL, NULL, :request_id, :created_at, :updated_at
        )";

        return $this->withReconnect(
            function (PDO $pdo) use ($sql, $queue, $type, $payload, $maxAttempts, $requestId, $now): int {
                $stmt = $pdo->prepare($sql);
                if (!$stmt instanceof PDOStatement) {
                    throw new \RuntimeException('Failed to prepare SQL statement');
                }

                $stmt->execute([
                    'queue' => $queue,
                    'type' => $type,
                    'payload' => json_encode($payload),
                    'max_attempts' => $maxAttempts,
                    'available_at' => $now,
                    'request_id' => $requestId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                return (int) $pdo->lastInsertId();
            }
        );
    }

    public function createJobs(array $jobs): array
    {
        if (empty($jobs)) {
            return [];
        }

        $now = $this->now();

        return $this->withReconnect(function (PDO $pdo) use ($jobs, $now): array {
            $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

            $columns = [
                'queue', 'type', 'status', 'payload', 'attempts', 'max_attempts',
                'available_at', 'request_id', 'created_at', 'updated_at'
            ];

            $placeholders = [];
            $params = [];

            foreach ($jobs as $job) {
                $placeholders[] = "(?, ?, 'pending', ?, 0, ?, ?, ?, ?, ?)";
                $params[] = $job['queue'] ?? 'default';
                $params[] = $job['type'];
                $params[] = json_encode($job['payload']);
                $params[] = $job['maxAttempts'] ?? 3;
                $params[] = $now;
                $params[] = $job['requestId'] ?? null;
                $params[] = $now;
                $params[] = $now;
            }

            $sql = "INSERT INTO {$this->table} (" . implode(', ', $columns) . ") " .
                "VALUES " . implode(', ', $placeholders);

            if ($driver === 'pgsql') {
                $sql .= " RETURNING id";
                $stmt = $pdo->prepare($sql);
                if (!$stmt instanceof PDOStatement) {
                    throw new \RuntimeException('Failed to prepare SQL statement');
                }
                $stmt->execute($params);
                return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN, 0));
            }

            $stmt = $pdo->prepare($sql);
            if (!$stmt instanceof PDOStatement) {
                throw new \RuntimeException('Failed to prepare SQL statement');
            }
            $stmt->execute($params);
            $count = $stmt->rowCount();

            if ($count === 0) {
                return [];
            }

            $lastId = (int) $pdo->lastInsertId();

            if ($driver === 'sqlite') {
                $firstId = $lastId - $count + 1;
                return range($firstId, $lastId);
            } else {
                $firstId = $lastId;
                return range($firstId, $firstId + $count - 1);
            }
        });
    }

    public function find(int $id): ?JobData
    {
        $sql = "SELECT * FROM {$this->table} WHERE id = :id";
        $stmt = $this->execute($sql, ['id' => $id]);

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

        $stmt = $this->execute($sql, ['request_id' => $requestId]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return JobData::fromRaw($row);
    }

    /**
     * Atomically claim the next available job in a queue.
     *
     * @param string $queue Queue name
     * @param string $workerId Worker identifier
     * @return ClaimedJob|null Claimed job or null when no job is available
     */
    public function claimNextAvailable(string $queue, string $workerId): ?ClaimedJob
    {
        $now = $this->now();
        $leaseToken = $this->generateLeaseToken();

        return $this->withReconnect(function (PDO $pdo) use ($queue, $workerId, $leaseToken, $now): ?ClaimedJob {
            $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

            if ($driver === 'pgsql') {
                return $this->claimNextAvailableWithReturning($pdo, $queue, $workerId, $leaseToken, $now);
            }

            return $this->claimNextAvailableWithTransaction($pdo, $driver, $queue, $workerId, $leaseToken, $now);
        });
    }

    /**
     * Atomically claim a specific job by ID.
     *
     * @param int $id Job identifier
     * @param string $workerId Worker identifier
     * @return ClaimedJob|null Claimed job or null when unavailable
     */
    public function claimById(int $id, string $workerId): ?ClaimedJob
    {
        $now = $this->now();
        $leaseToken = $this->generateLeaseToken();

        return $this->withReconnect(function (PDO $pdo) use ($id, $workerId, $leaseToken, $now): ?ClaimedJob {
            $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

            if ($driver === 'pgsql') {
                return $this->claimByIdWithReturning($pdo, $id, $workerId, $leaseToken, $now);
            }

            return $this->claimByIdWithTransaction($pdo, $driver, $id, $workerId, $leaseToken, $now);
        });
    }

    public function markCompleted(ClaimedJob $claim, mixed $result = null): bool
    {
        $now = $this->now();

        $sql = "UPDATE {$this->table}
            SET status = 'completed',
                result = :result,
                completed_at = :completed_at,
                locked_by = NULL,
                locked_at = NULL,
                lease_token = NULL,
                updated_at = :updated_at
            WHERE id = :id
            AND status = 'running'
            AND lease_token = :lease_token";

        $stmt = $this->execute($sql, [
            'id' => $claim->job->id,
            'lease_token' => $claim->leaseToken,
            'result' => $result === null ? null : json_encode($result),
            'completed_at' => $now,
            'updated_at' => $now,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function markFailed(ClaimedJob $claim, string $errorMessage, ?string $errorTrace = null): bool
    {
        $now = $this->now();

        $sql = "UPDATE {$this->table}
            SET status = 'failed',
                error_message = :error_message,
                error_trace = :error_trace,
                completed_at = :completed_at,
                locked_by = NULL,
                locked_at = NULL,
                lease_token = NULL,
                updated_at = :updated_at
            WHERE id = :id
            AND status = 'running'
            AND lease_token = :lease_token";

        $stmt = $this->execute($sql, [
            'id' => $claim->job->id,
            'lease_token' => $claim->leaseToken,
            'error_message' => $errorMessage,
            'error_trace' => $errorTrace,
            'completed_at' => $now,
            'updated_at' => $now,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function updateProgress(ClaimedJob $claim, ?int $progress = null, ?string $message = null): bool
    {
        $now = $this->now();

        $sql = "UPDATE {$this->table}
            SET progress = :progress,
                progress_message = :message,
                locked_at = :locked_at,
                updated_at = :updated_at
            WHERE id = :id
            AND status = 'running'
            AND lease_token = :lease_token";

        $stmt = $this->execute($sql, [
            'id' => $claim->job->id,
            'lease_token' => $claim->leaseToken,
            'progress' => $progress,
            'message' => $message,
            'locked_at' => $now,
            'updated_at' => $now,
        ]);

        if ($stmt->rowCount() > 0) {
            return true;
        }

        $checkSql = "SELECT 1 FROM {$this->table}
            WHERE id = :id
            AND status = 'running'
            AND lease_token = :lease_token";

        $checkStmt = $this->execute($checkSql, [
            'id' => $claim->job->id,
            'lease_token' => $claim->leaseToken,
        ]);

        return $checkStmt->fetch() !== false;
    }

    public function scheduleRetry(
        ClaimedJob $claim,
        int $attempts,
        int $delaySeconds,
        ?string $errorMessage = null
    ): bool {
        $now = $this->now();
        $availableAt = gmdate($this->dateFormat, (int) strtotime($now) + $delaySeconds);

        $sql = "UPDATE {$this->table}
            SET status = 'pending',
                attempts = :attempts,
                available_at = :available_at,
                error_message = :error_message,
                locked_by = NULL,
                locked_at = NULL,
                lease_token = NULL,
                updated_at = :updated_at
            WHERE id = :id
            AND status = 'running'
            AND lease_token = :lease_token";

        $stmt = $this->execute($sql, [
            'id' => $claim->job->id,
            'lease_token' => $claim->leaseToken,
            'attempts' => $attempts,
            'available_at' => $availableAt,
            'error_message' => $errorMessage,
            'updated_at' => $now,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function heartbeat(ClaimedJob $claim): bool
    {
        $now = $this->now();

        $sql = "UPDATE {$this->table}
            SET locked_at = :locked_at,
                updated_at = :updated_at
            WHERE id = :id
            AND status = 'running'
            AND lease_token = :lease_token";

        $stmt = $this->execute($sql, [
            'id' => $claim->job->id,
            'lease_token' => $claim->leaseToken,
            'locked_at' => $now,
            'updated_at' => $now,
        ]);

        if ($stmt->rowCount() > 0) {
            return true;
        }

        $checkSql = "SELECT 1 FROM {$this->table}
            WHERE id = :id
            AND status = 'running'
            AND lease_token = :lease_token";

        $checkStmt = $this->execute($checkSql, [
            'id' => $claim->job->id,
            'lease_token' => $claim->leaseToken,
        ]);

        return $checkStmt->fetch() !== false;
    }

    public function recoverStaleJobs(int $ttlSeconds): int
    {
        $now = $this->now();
        $staleThreshold = gmdate($this->dateFormat, (int) strtotime($now) - $ttlSeconds);

        // Fail poison jobs that have reached max attempts
        $sqlFailed = "UPDATE {$this->table}
            SET status = 'failed',
                error_message = 'Job timed out / worker crashed (stale recovery)',
                completed_at = :completed_at,
                locked_by = NULL,
                locked_at = NULL,
                lease_token = NULL,
                updated_at = :updated_at
            WHERE status = 'running'
            AND locked_at < :stale_threshold
            AND attempts + 1 >= max_attempts";

        $stmtFailed = $this->execute($sqlFailed, [
            'stale_threshold' => $staleThreshold,
            'completed_at' => $now,
            'updated_at' => $now,
        ]);
        $countFailed = $stmtFailed->rowCount();

        // Recover the rest to pending, incrementing attempts
        $sqlPending = "UPDATE {$this->table}
            SET status = 'pending',
                attempts = attempts + 1,
                locked_by = NULL,
                locked_at = NULL,
                lease_token = NULL,
                available_at = :available_at,
                updated_at = :updated_at
            WHERE status = 'running'
            AND locked_at < :stale_threshold
            AND attempts + 1 < max_attempts";

        $stmtPending = $this->execute($sqlPending, [
            'stale_threshold' => $staleThreshold,
            'available_at' => $now,
            'updated_at' => $now,
        ]);
        $countPending = $stmtPending->rowCount();

        return $countFailed + $countPending;
    }

    public function cancel(int $id): bool
    {
        $now = $this->now();
        $sql = "UPDATE {$this->table}
            SET status = 'cancelled', updated_at = :updated_at
            WHERE id = :id AND status = 'pending'";

        $stmt = $this->execute($sql, [
            'id' => $id,
            'updated_at' => $now,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Get jobs by status.
     *
     * @param JobStatus|null $status Filter by status (null for all)
     * @param string|null $queue Filter by queue (null for all)
     * @param int $limit Maximum number of jobs to return
     * @param int $offset Offset for pagination
     * @return JobData[]
     */
    public function list(?JobStatus $status = null, ?string $queue = null, int $limit = 100, int $offset = 0): array
    {
        $sql = "SELECT * FROM {$this->table} WHERE 1=1";
        $params = [];

        if ($status !== null) {
            $sql .= " AND status = :status";
            $params['status'] = $status->value;
        }

        if ($queue !== null) {
            $sql .= " AND queue = :queue";
            $params['queue'] = $queue;
        }

        $sql .= " ORDER BY id DESC LIMIT :limit OFFSET :offset";

        $stmt = $this->withReconnect(
            function (PDO $pdo) use ($sql, $params, $limit, $offset): PDOStatement {
                $stmt = $pdo->prepare($sql);
                if (!$stmt instanceof PDOStatement) {
                    throw new \RuntimeException('Failed to prepare SQL statement');
                }

                foreach ($params as $key => $value) {
                    $stmt->bindValue($key, $value);
                }
                $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
                $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
                $stmt->execute();

                return $stmt;
            }
        );

        $jobs = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $jobs[] = JobData::fromRaw($row);
        }

        return $jobs;
    }

    /**
     * Count jobs by status.
     *
     * @param JobStatus|null $status Filter by status (null for all)
     * @param string|null $queue Filter by queue (null for all)
     * @return int
     */
    public function count(?JobStatus $status = null, ?string $queue = null): int
    {
        $sql = "SELECT COUNT(*) as cnt FROM {$this->table} WHERE 1=1";
        $params = [];

        if ($status !== null) {
            $sql .= " AND status = :status";
            $params['status'] = $status->value;
        }

        if ($queue !== null) {
            $sql .= " AND queue = :queue";
            $params['queue'] = $queue;
        }

        $stmt = $this->execute($sql, $params);

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
        $threshold = gmdate(
            $this->dateFormat,
            $this->clock()->timestamp() - ($days * 86400)
        );

        $sql = "DELETE FROM {$this->table}
            WHERE status IN ('completed', 'cancelled')
            AND completed_at < :threshold";

        $stmt = $this->execute($sql, ['threshold' => $threshold]);

        return $stmt->rowCount();
    }

    protected function now(): string
    {
        return $this->clock()->now();
    }

    private function clock(): ClockInterface
    {
        return $this->clock ?? new SystemClock();
    }

    private function claimNextAvailableWithReturning(
        PDO $pdo,
        string $queue,
        string $workerId,
        string $leaseToken,
        string $now
    ): ?ClaimedJob {
        $sql = "UPDATE {$this->table}
            SET status = 'running',
                locked_by = :worker_id,
                locked_at = :locked_at,
                started_at = :started_at,
                lease_token = :lease_token,
                updated_at = :updated_at
            WHERE id = (
                SELECT id FROM {$this->table}
                WHERE status = 'pending'
                AND queue = :queue
                AND available_at <= :now
                ORDER BY available_at ASC, id ASC
                FOR UPDATE SKIP LOCKED
                LIMIT 1
            )
            RETURNING *";

        $stmt = $this->prepare($pdo, $sql);
        $stmt->execute([
            'queue' => $queue,
            'worker_id' => $workerId,
            'locked_at' => $now,
            'started_at' => $now,
            'lease_token' => $leaseToken,
            'updated_at' => $now,
            'now' => $now,
        ]);

        return $this->claimFromStatement($stmt, $workerId, $leaseToken);
    }

    private function claimByIdWithReturning(
        PDO $pdo,
        int $id,
        string $workerId,
        string $leaseToken,
        string $now
    ): ?ClaimedJob {
        $sql = "UPDATE {$this->table}
            SET status = 'running',
                locked_by = :worker_id,
                locked_at = :locked_at,
                started_at = :started_at,
                lease_token = :lease_token,
                updated_at = :updated_at
            WHERE id = :id
            AND (
                (status = 'pending' AND available_at <= :now)
                OR (status = 'running' AND locked_by = :worker_id_where)
            )
            RETURNING *";

        $stmt = $this->prepare($pdo, $sql);
        $stmt->execute([
            'id' => $id,
            'worker_id' => $workerId,
            'worker_id_where' => $workerId,
            'locked_at' => $now,
            'started_at' => $now,
            'lease_token' => $leaseToken,
            'updated_at' => $now,
            'now' => $now,
        ]);

        return $this->claimFromStatement($stmt, $workerId, $leaseToken);
    }

    private function claimNextAvailableWithTransaction(
        PDO $pdo,
        string $driver,
        string $queue,
        string $workerId,
        string $leaseToken,
        string $now
    ): ?ClaimedJob {
        $selectSql = "SELECT * FROM {$this->table}
            WHERE status = 'pending'
            AND queue = :queue
            AND available_at <= :now
            ORDER BY available_at ASC, id ASC
            LIMIT 1";

        if ($driver !== 'sqlite') {
            $selectSql .= ' FOR UPDATE SKIP LOCKED';
        }

        return $this->claimWithTransaction(
            $pdo,
            $driver,
            $selectSql,
            ['queue' => $queue, 'now' => $now],
            $workerId,
            $leaseToken,
            $now
        );
    }

    private function claimByIdWithTransaction(
        PDO $pdo,
        string $driver,
        int $id,
        string $workerId,
        string $leaseToken,
        string $now
    ): ?ClaimedJob {
        $selectSql = "SELECT * FROM {$this->table}
            WHERE id = :id
            AND (
                (status = 'pending' AND available_at <= :now)
                OR (status = 'running' AND locked_by = :worker_id)
            )
            LIMIT 1";

        if ($driver !== 'sqlite') {
            $selectSql .= ' FOR UPDATE SKIP LOCKED';
        }

        return $this->claimWithTransaction(
            $pdo,
            $driver,
            $selectSql,
            ['id' => $id, 'now' => $now, 'worker_id' => $workerId],
            $workerId,
            $leaseToken,
            $now
        );
    }

    /**
     * @param array<string, mixed> $selectParams
     */
    private function claimWithTransaction(
        PDO $pdo,
        string $driver,
        string $selectSql,
        array $selectParams,
        string $workerId,
        string $leaseToken,
        string $now
    ): ?ClaimedJob {
        $began = false;

        try {
            if ($driver === 'sqlite') {
                $pdo->exec('BEGIN IMMEDIATE');
            } else {
                $pdo->beginTransaction();
            }
            $began = true;

            $select = $this->prepare($pdo, $selectSql);
            $select->execute($selectParams);

            $row = $select->fetch(PDO::FETCH_ASSOC);
            if ($row === false || empty($row['id'])) {
                if ($driver === 'sqlite') {
                    $pdo->exec('COMMIT');
                } else {
                    $pdo->commit();
                }
                return null;
            }

            $id = (int) $row['id'];
            $updateSql = "UPDATE {$this->table}
                SET status = 'running',
                    locked_by = :worker_id,
                    locked_at = :locked_at,
                    started_at = :started_at,
                    lease_token = :lease_token,
                    updated_at = :updated_at
                WHERE id = :id
                AND (
                    (status = 'pending' AND available_at <= :now)
                    OR (status = 'running' AND locked_by = :worker_id_where)
                )";

            $update = $this->prepare($pdo, $updateSql);
            $update->execute([
                'id' => $id,
                'worker_id' => $workerId,
                'worker_id_where' => $workerId,
                'locked_at' => $now,
                'started_at' => $now,
                'lease_token' => $leaseToken,
                'updated_at' => $now,
                'now' => $now,
            ]);

            if ($update->rowCount() === 0) {
                if ($driver === 'sqlite') {
                    $pdo->exec('COMMIT');
                } else {
                    $pdo->commit();
                }
                return null;
            }

            $find = $this->prepare($pdo, "SELECT * FROM {$this->table} WHERE id = :id");
            $find->execute(['id' => $id]);

            if ($driver === 'sqlite') {
                $pdo->exec('COMMIT');
            } else {
                $pdo->commit();
            }

            return $this->claimFromStatement($find, $workerId, $leaseToken);
        } catch (\Throwable $e) {
            if ($began) {
                if ($driver === 'sqlite') {
                    try {
                        $pdo->exec('ROLLBACK');
                    } catch (\Throwable $_) {
                        // Suppress rollback error
                    }
                } elseif ($pdo->inTransaction()) {
                    try {
                        $pdo->rollBack();
                    } catch (\Throwable $_) {
                        // Suppress rollback error to avoid masking the original exception
                    }
                }
            }

            throw $e;
        }
    }

    private function claimFromStatement(PDOStatement $stmt, string $workerId, string $leaseToken): ?ClaimedJob
    {
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return new ClaimedJob(JobData::fromRaw($row), $workerId, $leaseToken);
    }

    private function generateLeaseToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    private function prepare(PDO $pdo, string $sql): PDOStatement
    {
        $stmt = $pdo->prepare($sql);
        if (!$stmt instanceof PDOStatement) {
            throw new \RuntimeException('Failed to prepare SQL statement');
        }

        return $stmt;
    }
}
