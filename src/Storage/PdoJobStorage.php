<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Storage;

use Oeltima\SimpleQueue\Contract\ClockInterface;
use Oeltima\SimpleQueue\Contract\ClaimedJob;
use Oeltima\SimpleQueue\Contract\JobData;
use Oeltima\SimpleQueue\Contract\JobStatus;
use Oeltima\SimpleQueue\Contract\JobStorageAdminInterface;
use Oeltima\SimpleQueue\Contract\JobStorageInterface;
use Oeltima\SimpleQueue\Contract\IdempotentJobResult;
use Oeltima\SimpleQueue\Contract\SupportsIdempotentJobCreation;
use Oeltima\SimpleQueue\Contract\SupportsPendingJobCursor;
use Oeltima\SimpleQueue\Contract\SupportsQueueScopedStaleRecovery;
use Oeltima\SimpleQueue\Internal\JobFilter;
use Oeltima\SimpleQueue\Internal\JobStorageRules;
use Oeltima\SimpleQueue\Internal\PdoClaimTransaction;
use Oeltima\SimpleQueue\Internal\RetryDecision;
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
 * @phpstan-import-type JobDefinitionShape from JobStorageInterface
 *
 * Supports auto-reconnect for long-running workers via connection factory.
 */
class PdoJobStorage implements
    JobStorageInterface,
    JobStorageAdminInterface,
    SupportsIdempotentJobCreation,
    SupportsPendingJobCursor,
    SupportsQueueScopedStaleRecovery
{
    protected ?PDO $pdo = null;

    /** @var callable(): PDO|null Factory function to create PDO connection */
    protected $connectionFactory = null;

    protected string $dateFormat = 'Y-m-d H:i:s';

    /**
     * @param PDO|callable $connection PDO instance or factory callable (fn(): PDO)
     * @param string $table Table name for jobs (default: 'background_jobs')
     * @param ClockInterface|null $clock Clock implementation
     */
    public function __construct(
        #[\SensitiveParameter] PDO|callable $connection,
        protected string $table = 'background_jobs',
        private readonly ?ClockInterface $clock = null
    ) {
        if ($connection instanceof PDO) {
            $this->pdo = $this->configurePdo($connection);
        } else {
            $this->connectionFactory = $connection;
        }
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
            $stmt = $this->prepare($pdo, $sql);
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

    /**
     * Create a new job record.
     *
     * @param string $type Job type identifier
     * @param array<string, mixed> $payload Job payload data
     * @param string $queue Queue name
     * @param int $maxAttempts Maximum retry attempts
     * @param string|null $requestId Optional request correlation ID
     * @return int The created job ID
     */
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
                $stmt = $this->prepare($pdo, $sql);
                $stmt->execute([
                    'queue' => $queue,
                    'type' => $type,
                    'payload' => JobStorageRules::encodeJson($payload, 'job payload'),
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

    /**
     * Batch create multiple job records in a single operation.
     *
     * @param array<int, JobDefinitionShape> $jobs Array of job definitions
     * @return int[] Array of created job IDs
     */
    public function createJobs(array $jobs): array
    {
        if ($jobs === []) {
            return [];
        }

        $now = $this->now();

        return $this->withReconnect(function (PDO $pdo) use ($jobs, $now): array {
            $driverAttr = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            $driver = is_string($driverAttr) ? $driverAttr : '';

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
                $params[] = JobStorageRules::encodeJson($job['payload'], 'job payload');
                $params[] = $job['maxAttempts'] ?? 3;
                $params[] = $this->rowAvailableAt($job, $now);
                $params[] = $job['requestId'] ?? null;
                $params[] = $now;
                $params[] = $now;
            }

            $sql = "INSERT INTO {$this->table} (" . implode(', ', $columns) . ") " .
                "VALUES " . implode(', ', $placeholders);

            if ($driver === 'pgsql') {
                $sql .= " RETURNING id";
                $stmt = $this->prepare($pdo, $sql);
                $stmt->execute($params);
                return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN, 0));
            }

            $stmt = $this->prepare($pdo, $sql);
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

        return $this->fetchJob($stmt);
    }

    public function findActiveByRequestId(string $requestId): ?JobData
    {
        $sql = "SELECT * FROM {$this->table}
            WHERE request_id = :request_id
            AND status IN ('pending', 'running')
            LIMIT 1";

        $stmt = $this->execute($sql, ['request_id' => $requestId]);

        return $this->fetchJob($stmt);
    }

    /**
     * @param array<string, mixed> $payload Job payload
     */
    public function createIdempotentJob(
        string $type,
        array $payload,
        string $requestId,
        string $queue,
        int $maxAttempts
    ): IdempotentJobResult {
        // The database conditional/generated unique index is the concurrency authority.
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $result = $this->attemptCreateIdempotentJob($type, $payload, $requestId, $queue, $maxAttempts);
            if ($result !== null) {
                return $result;
            }
        }

        throw new \RuntimeException('Could not resolve concurrent idempotent job creation');
    }

    /**
     * @param array<string, mixed> $payload Job payload
     */
    private function attemptCreateIdempotentJob(
        string $type,
        array $payload,
        string $requestId,
        string $queue,
        int $maxAttempts
    ): ?IdempotentJobResult {
        $existing = $this->findActiveByRequestId($requestId);
        if ($existing !== null) {
            return new IdempotentJobResult($existing->id, false);
        }

        $pdo = $this->getPdo();
        $hasSavepoint = $pdo->inTransaction();
        if ($hasSavepoint) {
            $pdo->exec('SAVEPOINT simplequeue_idempotent_job');
        }

        try {
            $result = new IdempotentJobResult(
                $this->createJob($type, $payload, $queue, $maxAttempts, $requestId),
                true
            );
            if ($hasSavepoint) {
                $pdo->exec('RELEASE SAVEPOINT simplequeue_idempotent_job');
            }
            return $result;
        } catch (PDOException $exception) {
            if (!$this->isUniqueConstraintViolation($exception)) {
                throw $exception;
            }

            $this->rollbackIdempotentSavepoint($pdo, $hasSavepoint);

            $existing = $this->findActiveByRequestId($requestId);
            if ($existing !== null) {
                return new IdempotentJobResult($existing->id, false);
            }
        }

        return null;
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
        return $this->claimJob($queue, null, $workerId);
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
        return $this->claimJob(null, $id, $workerId);
    }

    private function claimJob(?string $queue, ?int $id, string $workerId): ?ClaimedJob
    {
        $now = $this->now();
        $leaseToken = $this->generateLeaseToken();

        return $this->withReconnect(function (PDO $pdo) use ($queue, $id, $workerId, $leaseToken, $now): ?ClaimedJob {
            $driverAttr = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            $driver = is_string($driverAttr) ? $driverAttr : '';

            if ($driver === 'pgsql') {
                return $this->claimWithReturning($pdo, $queue, $id, $workerId, $leaseToken, $now);
            }

            return $this->claimJobWithTransaction($pdo, $driver, $queue, $id, $workerId, $leaseToken, $now);
        });
    }

    public function markCompleted(ClaimedJob $claim, mixed $result = null): bool
    {
        $now = $this->now();

        $stmt = $this->executeClaimUpdate($claim, "status = 'completed',
            result = :result, completed_at = :completed_at,
            locked_by = NULL, locked_at = NULL, lease_token = NULL,
            updated_at = :updated_at", [
            'result' => $result === null ? null : JobStorageRules::encodeJson($result, 'job result'),
            'completed_at' => $now,
            'updated_at' => $now,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function markFailed(ClaimedJob $claim, string $errorMessage, ?string $errorTrace = null): bool
    {
        $now = $this->now();

        $stmt = $this->executeClaimUpdate($claim, "status = 'failed',
            error_message = :error_message, error_trace = :error_trace,
            completed_at = :completed_at, locked_by = NULL, locked_at = NULL,
            lease_token = NULL, updated_at = :updated_at", [
            'error_message' => $errorMessage,
            'error_trace' => $errorTrace,
            'completed_at' => $now,
            'updated_at' => $now,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function updateProgress(ClaimedJob $claim, ?int $progress = null, ?string $message = null): bool
    {
        JobStorageRules::validateProgress($progress);
        $now = $this->now();

        $stmt = $this->executeClaimUpdate($claim, 'progress = :progress,
            progress_message = :message, locked_at = :locked_at,
            updated_at = :updated_at', [
            'progress' => $progress,
            'message' => $message,
            'locked_at' => $now,
            'updated_at' => $now,
        ]);

        if ($stmt->rowCount() > 0) {
            return true;
        }

        return $this->claimStillOwned($claim);
    }

    public function scheduleRetry(
        ClaimedJob $claim,
        int $attempts,
        int $delaySeconds,
        ?string $errorMessage = null
    ): bool {
        JobStorageRules::validateRetry($attempts, $delaySeconds);
        $now = $this->now();
        $availableAt = JobStorageRules::timestamp($this->clock(), $this->dateFormat, $delaySeconds);

        $stmt = $this->executeClaimUpdate($claim, "status = 'pending',
            attempts = :attempts, available_at = :available_at,
            error_message = :error_message, locked_by = NULL, locked_at = NULL,
            lease_token = NULL, updated_at = :updated_at", [
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

        $stmt = $this->executeClaimUpdate($claim, 'locked_at = :locked_at,
            updated_at = :updated_at', [
            'locked_at' => $now,
            'updated_at' => $now,
        ]);

        if ($stmt->rowCount() > 0) {
            return true;
        }

        return $this->claimStillOwned($claim);
    }

    public function recoverStaleJobs(int $ttlSeconds): int
    {
        $now = $this->now();
        $staleThreshold = JobStorageRules::timestamp($this->clock(), $this->dateFormat, -$ttlSeconds);

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

    public function recoverStaleJobsForQueue(string $queue, int $ttlSeconds, int $limit): int
    {
        JobStorageRules::validateStaleRecovery($ttlSeconds, $limit);
        $now = $this->now();
        $threshold = JobStorageRules::timestamp($this->clock(), $this->dateFormat, -$ttlSeconds);
        $sql = "SELECT * FROM {$this->table} WHERE queue = :queue AND status = 'running' " .
            'AND locked_at < :threshold ORDER BY locked_at ASC LIMIT :limit';
        $params = ['queue' => $queue, 'threshold' => $threshold];
        $statement = $this->withReconnect(
            fn (PDO $pdo): PDOStatement => $this->boundedStatement($pdo, $sql, $params, $limit)
        );
        $recovered = 0;
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $job = JobData::fromRaw($row);
            $terminal = !RetryDecision::forAttempt($job->attempts + 1, $job->maxAttempts)->shouldRetry();
            $update = "UPDATE {$this->table} SET status = :status, attempts = :attempts, " .
                'available_at = :available_at, completed_at = :completed_at, locked_by = NULL, ' .
                "locked_at = NULL, lease_token = NULL, updated_at = :updated_at WHERE id = :id " .
                "AND status = 'running' AND locked_at < :threshold";
            $changed = $this->execute($update, [
                'status' => $terminal ? 'failed' : 'pending',
                'attempts' => $job->attempts + 1,
                'available_at' => $now,
                'completed_at' => $terminal ? $now : null,
                'updated_at' => $now,
                'id' => $job->id,
                'threshold' => $threshold,
            ]);
            $recovered += $changed->rowCount();
        }
        return $recovered;
    }

    public function cancel(int $id): bool
    {
        $now = $this->now();
        $sql = "UPDATE {$this->table}
            SET status = 'cancelled', completed_at = :completed_at,
                locked_by = NULL, locked_at = NULL, lease_token = NULL, updated_at = :updated_at
            WHERE id = :id AND status = 'pending'";

        $stmt = $this->execute($sql, [
            'id' => $id,
            'completed_at' => $now,
            'updated_at' => $now,
        ]);

        return $stmt->rowCount() > 0;
    }

    private function isUniqueConstraintViolation(PDOException $exception): bool
    {
        return in_array((string) $exception->getCode(), ['23000', '23505'], true)
            || (string) ($exception->errorInfo[0] ?? '') === '23000';
    }

    private function rollbackIdempotentSavepoint(PDO $pdo, bool $hasSavepoint): void
    {
        if (!$hasSavepoint) {
            return;
        }

        $pdo->exec('ROLLBACK TO SAVEPOINT simplequeue_idempotent_job');
        $pdo->exec('RELEASE SAVEPOINT simplequeue_idempotent_job');
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
        [$sql, $params] = $this->filteredQuery('SELECT *', new JobFilter($status, $queue));
        $sql .= " ORDER BY id DESC LIMIT :limit OFFSET :offset";

        $stmt = $this->withReconnect(
            fn (PDO $pdo): PDOStatement => $this->boundedStatement($pdo, $sql, $params, $limit, $offset)
        );
        return $this->fetchJobs($stmt);
    }

    /**
     * @return list<JobData>
     */
    public function scanPending(string $queue, ?int $afterId, int $limit): array
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('Scan limit must be positive');
        }
        $sql = "SELECT * FROM {$this->table} WHERE queue = :queue AND status = 'pending'";
        $params = ['queue' => $queue];
        if ($afterId !== null) {
            $sql .= ' AND id > :after_id';
            $params['after_id'] = $afterId;
        }
        $sql .= ' ORDER BY id ASC LIMIT :limit';
        $stmt = $this->withReconnect(
            fn (PDO $pdo): PDOStatement => $this->boundedStatement($pdo, $sql, $params, $limit)
        );
        return $this->fetchJobs($stmt);
    }

    /**
     * Count jobs by status.
     *
     * @param JobStatus|null $status Filter by status (null for all)
     * @param string|null $queue Filter by queue (null for all)
     */
    public function count(?JobStatus $status = null, ?string $queue = null): int
    {
        [$sql, $params] = $this->filteredQuery('SELECT COUNT(*) as cnt', new JobFilter($status, $queue));
        $stmt = $this->execute($sql, $params);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) && isset($row['cnt']) && is_numeric($row['cnt']) ? (int) $row['cnt'] : 0;
    }

    /**
     * Delete completed jobs older than a given number of days.
     *
     * @param int $days Number of days to keep completed jobs
     * @return int Number of deleted jobs
     */
    public function pruneCompleted(int $days = 7): int
    {
        $threshold = JobStorageRules::timestamp($this->clock(), $this->dateFormat, -$days * 86400);

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

    /**
     * Resolve the available-at timestamp for one createJobs() definition.
     *
     * @param array<string, mixed> $job Job definition
     * @param string $now Default "now" timestamp for absent keys
     * @return string UTC timestamp the job becomes available
     */
    private function rowAvailableAt(array $job, string $now): string
    {
        $availableAt = $job['availableAt'] ?? null;

        return $availableAt === null
            ? $now
            : JobStorageRules::normalizeAvailableAt($availableAt, $this->clock());
    }

    private function clock(): ClockInterface
    {
        return $this->clock ?? new SystemClock();
    }

    /** @return array{string, array<string, string>} */
    private function filteredQuery(string $select, JobFilter $filter): array
    {
        $sql = "{$select} FROM {$this->table} WHERE 1=1";
        $params = [];
        if ($filter->status !== null) {
            $sql .= ' AND status = :status';
            $params['status'] = $filter->status->value;
        }
        if ($filter->queue !== null) {
            $sql .= ' AND queue = :queue';
            $params['queue'] = $filter->queue;
        }
        return [$sql, $params];
    }

    /**
     * @param array<string, mixed> $params
     */
    private function executeClaimUpdate(ClaimedJob $claim, string $assignments, array $params): PDOStatement
    {
        $sql = "UPDATE {$this->table} SET {$assignments}
            WHERE id = :id AND status = 'running' AND lease_token = :lease_token";
        return $this->execute($sql, array_merge($this->claimIdentity($claim), $params));
    }

    private function claimStillOwned(ClaimedJob $claim): bool
    {
        $sql = "SELECT 1 FROM {$this->table}
            WHERE id = :id AND status = 'running' AND lease_token = :lease_token";
        return $this->execute($sql, $this->claimIdentity($claim))->fetch() !== false;
    }

    /** @return array{id: int, lease_token: string} */
    private function claimIdentity(ClaimedJob $claim): array
    {
        return ['id' => $claim->job->id, 'lease_token' => $claim->leaseToken];
    }

    /**
     * @param array<string, mixed> $params
     */
    private function boundedStatement(
        PDO $pdo,
        string $sql,
        array $params,
        int $limit,
        ?int $offset = null
    ): PDOStatement {
        $statement = $this->prepare($pdo, $sql);
        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value);
        }
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        if ($offset !== null) {
            $statement->bindValue('offset', $offset, PDO::PARAM_INT);
        }
        $statement->execute();
        return $statement;
    }

    /** @return list<JobData> */
    private function fetchJobs(PDOStatement $statement): array
    {
        return array_values(array_map(
            fn (array $row): JobData => JobData::fromRaw($this->associativeRow($row)),
            $statement->fetchAll(PDO::FETCH_ASSOC)
        ));
    }

    private function fetchJob(PDOStatement $statement): ?JobData
    {
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? JobData::fromRaw($this->associativeRow($row)) : null;
    }

    /**
     * @param array<array-key, mixed> $row
     * @return array<string, mixed>
     */
    private function associativeRow(array $row): array
    {
        $result = [];
        foreach ($row as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }
        return $result;
    }

    private function claimWithReturning(
        PDO $pdo,
        ?string $queue,
        ?int $id,
        string $workerId,
        string $leaseToken,
        string $now
    ): ?ClaimedJob {
        if ($id !== null) {
            $whereClause = "WHERE id = :id
            AND (
                (status = 'pending' AND available_at <= :now)
                OR (status = 'running' AND locked_by = :worker_id_where)
            )";
            $params = [
                'id' => $id,
                'worker_id' => $workerId,
                'worker_id_where' => $workerId,
                'locked_at' => $now,
                'started_at' => $now,
                'lease_token' => $leaseToken,
                'updated_at' => $now,
                'now' => $now,
            ];
        } else {
            $whereClause = "WHERE id = (
                SELECT id FROM {$this->table}
                WHERE status = 'pending'
                AND queue = :queue
                AND available_at <= :now
                ORDER BY available_at ASC, id ASC
                FOR UPDATE SKIP LOCKED
                LIMIT 1
            )";
            $params = [
                'queue' => $queue,
                'worker_id' => $workerId,
                'locked_at' => $now,
                'started_at' => $now,
                'lease_token' => $leaseToken,
                'updated_at' => $now,
                'now' => $now,
            ];
        }

        $sql = "UPDATE {$this->table}
            SET status = 'running',
                locked_by = :worker_id,
                locked_at = :locked_at,
                started_at = :started_at,
                lease_token = :lease_token,
                updated_at = :updated_at
            {$whereClause}
            RETURNING *";

        $stmt = $this->prepare($pdo, $sql);
        $stmt->execute($params);

        return $this->claimFromStatement($stmt, $workerId, $leaseToken);
    }

    private function claimJobWithTransaction(
        PDO $pdo,
        string $driver,
        ?string $queue,
        ?int $id,
        string $workerId,
        string $leaseToken,
        string $now
    ): ?ClaimedJob {
        if ($id !== null) {
            $selectSql = "SELECT * FROM {$this->table}
                WHERE id = :id
                AND (
                    (status = 'pending' AND available_at <= :now)
                    OR (status = 'running' AND locked_by = :worker_id)
                )
                LIMIT 1";
            $selectParams = ['id' => $id, 'now' => $now, 'worker_id' => $workerId];
        } else {
            $selectSql = "SELECT * FROM {$this->table}
                WHERE status = 'pending'
                AND queue = :queue
                AND available_at <= :now
                ORDER BY available_at ASC, id ASC
                LIMIT 1";
            $selectParams = ['queue' => $queue, 'now' => $now];
        }

        if ($driver !== 'sqlite') {
            $selectSql .= ' FOR UPDATE SKIP LOCKED';
        }

        $transaction = new PdoClaimTransaction($pdo, $driver);
        $transaction->begin();

        try {
            $statement = $this->claimWithinTransaction(
                $pdo,
                $selectSql,
                $selectParams,
                $workerId,
                $leaseToken,
                $now
            );
            $transaction->commit();
            return $statement === null ? null : $this->claimFromStatement($statement, $workerId, $leaseToken);
        } catch (\Throwable $exception) {
            $transaction->rollbackIgnoringFailure();
            throw $exception;
        }
    }

    /**
     * @param array<string, mixed> $selectParams
     */
    private function claimWithinTransaction(
        PDO $pdo,
        string $selectSql,
        array $selectParams,
        string $workerId,
        string $leaseToken,
        string $now
    ): ?PDOStatement {
        $select = $this->prepare($pdo, $selectSql);
        $select->execute($selectParams);
        $id = $this->claimRowId($select->fetch(PDO::FETCH_ASSOC));
        if ($id === null) {
            return null;
        }

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
            return null;
        }

        $find = $this->prepare($pdo, "SELECT * FROM {$this->table} WHERE id = :id");
        $find->execute(['id' => $id]);
        return $find;
    }

    private function claimRowId(mixed $row): ?int
    {
        if (!is_array($row)) {
            return null;
        }

        $rowId = $row['id'] ?? 0;
        $id = is_int($rowId) ? $rowId : (is_numeric($rowId) ? (int) $rowId : 0);
        return $id === 0 ? null : $id;
    }

    private function claimFromStatement(PDOStatement $stmt, string $workerId, string $leaseToken): ?ClaimedJob
    {
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        /** @var array<string, mixed> $row */
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
