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
use Oeltima\SimpleQueue\Contract\SupportsFailedJobAdministration;
use Oeltima\SimpleQueue\Contract\SupportsPendingJobCursor;
use Oeltima\SimpleQueue\Contract\SupportsPendingNotificationCursor;
use Oeltima\SimpleQueue\Contract\PendingNotification;
use Oeltima\SimpleQueue\Contract\SupportsQueueScopedStaleRecovery;
use Oeltima\SimpleQueue\Exception\IndeterminateStorageOutcomeException;
use Oeltima\SimpleQueue\Internal\JobDataHydrator;
use Oeltima\SimpleQueue\Internal\JobFilter;
use Oeltima\SimpleQueue\Internal\JobStorageRules;
use Oeltima\SimpleQueue\Internal\PdoClaimTransaction;
use Oeltima\SimpleQueue\Internal\PdoBatchJobInserter;
use Oeltima\SimpleQueue\Internal\PdoFailedJobAdministration;
use Oeltima\SimpleQueue\Internal\PdoStaleJobRecovery;
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
 * @phpstan-import-type ValidatedJobShape from JobStorageRules
 * @phpstan-type IdempotentRequest array{
 *     type: string,
 *     payload: array<string, mixed>,
 *     requestId: string,
 *     queue: string,
 *     maxAttempts: int
 * }
 * @phpstan-type ClaimContext array{
 *     pdo: PDO,
 *     driver: string,
 *     queue: string|null,
 *     id: int|null,
 *     workerId: string,
 *     leaseToken: string,
 *     now: string
 * }
 * @phpstan-type ClaimSelection array{sql: string, params: array<string, mixed>}
 *
 * Supports auto-reconnect for long-running workers via connection factory.
 */
class PdoJobStorage implements
    JobStorageInterface,
    JobStorageAdminInterface,
    SupportsIdempotentJobCreation,
    SupportsFailedJobAdministration,
    SupportsPendingJobCursor,
    SupportsPendingNotificationCursor,
    SupportsQueueScopedStaleRecovery
{
    use PdoFailedJobAdministration;

    protected ?PDO $pdo = null;
    /** @var callable(): PDO|null Factory function to create PDO connection */
    protected $connectionFactory = null;

    protected string $dateFormat = 'Y-m-d H:i:s';

    private readonly PdoBatchJobInserter $batchInserter;
    private readonly PdoStaleJobRecovery $staleJobRecovery;

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
        $this->table = JobStorageRules::validateTableName($table);
        $this->batchInserter = new PdoBatchJobInserter(['table' => $this->table]);
        $this->staleJobRecovery = new PdoStaleJobRecovery([
            'table' => $this->table,
            'now' => fn (): string => $this->now(),
            'threshold' => fn (int $ttl): string =>
                JobStorageRules::timestamp($this->clock(), $this->dateFormat, -$ttl),
            'mutate' => $this->executeStaleMutation(...),
            'rows' => $this->staleRows(...),
        ]);
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
     *
     * Factory-backed storage reconnects lazily. Direct-PDO instances reject
     * the call without discarding their usable connection.
     */
    public function reconnect(): void
    {
        if ($this->connectionFactory === null) {
            throw new \LogicException('Direct-PDO storage has no connection factory to reconnect with');
        }
        $this->pdo = null;
    }

    /**
     * Run a read operation, retrying once after a connection-loss exception.
     *
     * Reads retry only when a connection factory exists and the connection
     * is not inside a caller-owned transaction.
     *
     * @template T
     * @param callable(PDO): T $operation Database operation
     * @return T Operation result
     */
    protected function withReconnect(callable $operation): mixed
    {
        try {
            $pdo = $this->getPdo();
        } catch (PDOException $e) {
            if (!$this->canReconnect($e, false)) {
                throw $e;
            }
            // Connection acquisition failed before an operation could run.
            $this->pdo = null;
            $pdo = $this->getPdo();
        }

        // Capture ownership before the operation. A dropped connection can
        // report no active transaction afterward, but replay is still unsafe.
        $insideTransaction = $pdo->inTransaction();
        try {
            return $operation($pdo);
        } catch (PDOException $e) {
            if (!$this->canReconnect($e, $insideTransaction)) {
                throw $e;
            }
            $this->discardFactoryConnection($pdo);
            return $operation($this->getPdo());
        }
    }

    private function canReconnect(PDOException $exception, bool $insideTransaction): bool
    {
        if ($this->connectionFactory === null) {
            return false;
        }
        if ($insideTransaction) {
            return false;
        }
        return $this->isConnectionException($exception);
    }

    /**
     * Run a mutation without replaying after a statement or commit attempt.
     *
     * Connection creation before the mutation begins may retry once. After
     * that boundary, a connection/commit failure is indeterminate (the write
     * may already have committed) and raises IndeterminateStorageOutcomeException.
     *
     * @template T
     * @param string $operation Operation name for indeterminate errors
     * @param callable(PDO): T $mutation Mutation callback
     * @return T Mutation result
     */
    protected function withMutation(string $operation, callable $mutation): mixed
    {
        $pdo = $this->freshPdoForMutation();
        try {
            return $mutation($pdo);
        } catch (PDOException $e) {
            if ($this->isConnectionException($e)) {
                $this->discardFactoryConnection($pdo);
                throw IndeterminateStorageOutcomeException::forOperation($operation, $e);
            }
            throw $e;
        }
    }

    /**
     * Acquire a PDO handle for a mutation, retrying creation once.
     *
     * @return PDO Fresh or existing PDO handle
     */
    private function freshPdoForMutation(): PDO
    {
        try {
            return $this->getPdo();
        } catch (PDOException $e) {
            if ($this->connectionFactory === null || !$this->isConnectionException($e)) {
                throw $e;
            }
            $this->pdo = null;
            return $this->getPdo();
        }
    }

    /**
     * Discard only a factory-owned failed connection for the next call.
     *
     * @param PDO $pdo Connection that observed the failure
     */
    private function discardFactoryConnection(PDO $pdo): void
    {
        if ($this->connectionFactory !== null && $this->pdo === $pdo) {
            $this->pdo = null;
        }
    }

    /**
     * Prepare and execute a SQL statement with reconnect support.
     *
     * Read-safe path: retries once only with a factory and outside
     * caller-owned transactions.
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
     * Prepare and execute a mutation without transparent replay.
     *
     * @param string $sql SQL statement
     * @param array<string, mixed> $params Bound parameters
     * @param string $operation Operation name for indeterminate errors
     * @return PDOStatement Executed statement
     */
    protected function executeMutation(string $sql, array $params, string $operation): PDOStatement
    {
        return $this->withMutation($operation, function (PDO $pdo) use ($sql, $params): PDOStatement {
            $stmt = $this->prepare($pdo, $sql);
            $stmt->execute($params);
            return $stmt;
        });
    }

    /**
     * Check whether a PDO exception likely represents a lost connection.
     *
     * Classifies SQLSTATE class 08 plus verified MySQL connection codes
     * without misclassifying constraint, serialization, or deadlock errors.
     *
     * @param PDOException $e PDO exception
     * @return bool True for connection-loss errors
     */
    protected function isConnectionException(PDOException $e): bool
    {
        $sqlState = $this->sqlState($e);
        // Never misclassify constraint (23xxx), serialization/deadlock (40xxx).
        if ($this->isNonConnectionState($sqlState)) {
            return false;
        }
        // SQLSTATE connection-exception class.
        if (str_starts_with($sqlState, '08')) {
            return true;
        }
        // MySQL deadlock (1213) and lock timeout (1205) are not connection loss.
        $errorInfoCode = isset($e->errorInfo[1]) ? (string) $e->errorInfo[1] : '';
        if (in_array($errorInfoCode, ['1213', '1205'], true)) {
            return false;
        }
        if ($this->isConnectionCode((string) $e->getCode())) {
            return true;
        }
        if ($this->isConnectionCode($errorInfoCode)) {
            return true;
        }
        return $this->hasConnectionMessage($e);
    }

    private function sqlState(PDOException $exception): string
    {
        $errorInfoState = $exception->errorInfo[0] ?? null;
        if (is_string($errorInfoState)) {
            return $errorInfoState;
        }
        $code = $exception->getCode();
        if (!is_string($code)) {
            return '';
        }
        return strlen($code) === 5 ? $code : '';
    }

    private function isNonConnectionState(string $sqlState): bool
    {
        if (str_starts_with($sqlState, '23')) {
            return true;
        }
        return str_starts_with($sqlState, '40');
    }

    private function isConnectionCode(string $code): bool
    {
        return in_array($code, ['2002', '2003', '2006', '2013', '08003', '08006'], true);
    }

    private function hasConnectionMessage(PDOException $exception): bool
    {
        $message = strtolower($exception->getMessage());
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
        JobStorageRules::validateQueueOrType($queue, 'Queue');
        JobStorageRules::validateQueueOrType($type, 'Job type');
        JobStorageRules::validateMaxAttempts($maxAttempts);
        if ($requestId !== null) {
            JobStorageRules::validateBoundedString($requestId, 'Request ID');
            if (trim($requestId) === '') {
                throw new \InvalidArgumentException('Request ID must not be empty when provided');
            }
        }
        $encodedPayload = JobStorageRules::encodeJson($payload, 'job payload');
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

        return $this->withMutation(
            'createJob',
            function (PDO $pdo) use ($sql, $queue, $type, $encodedPayload, $maxAttempts, $requestId, $now): int {
                $stmt = $this->prepare($pdo, $sql);
                $stmt->execute([
                    'queue' => $queue,
                    'type' => $type,
                    'payload' => $encodedPayload,
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
     * All definitions are validated and JSON-encoded before any row is
     * mutated. Chunks hold one transaction/savepoint across the whole
     * logical batch so a later failure rolls back earlier chunks.
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
        $clock = $this->clock();
        // Validate every definition (and encode payloads) before mutating rows.
        $validated = [];
        foreach ($jobs as $job) {
            $validated[] = JobStorageRules::validateJobDefinition($job, $clock);
        }

        return $this->withMutation(
            'createJobs',
            fn (PDO $pdo): array => $this->insertValidatedJobs($pdo, $validated, $now)
        );
    }

    /**
     * @param list<ValidatedJobShape> $validated Validated job definitions
     * @return list<int> Exact inserted IDs
     */
    private function insertValidatedJobs(PDO $pdo, array $validated, string $now): array
    {
        $driverAttribute = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $driver = is_string($driverAttribute) ? $driverAttribute : '';
        $ownsTransaction = !$pdo->inTransaction();
        $savepoint = 'simplequeue_batch_create';
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT ' . $savepoint);
        }

        try {
            $ids = [];
            foreach ($this->chunkValidatedJobs($validated) as $chunk) {
                array_push($ids, ...$this->insertValidatedChunk($pdo, $driver, $chunk, $now));
            }
            if ($ownsTransaction) {
                $pdo->commit();
            } else {
                $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }
            return $ids;
        } catch (\Throwable $exception) {
            try {
                if ($ownsTransaction) {
                    $pdo->rollBack();
                } else {
                    $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                    $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
                }
            } catch (\Throwable) {
                // Preserve the original failure; withMutation classifies ambiguous outcomes.
            }
            throw $exception;
        }
    }

    /**
     * Chunk validated jobs by row count and ~1MiB encoded parameter budget.
     *
     * Row caps (SQLite 100, MySQL/PostgreSQL 1000) are enforced at insert
     * time per driver; this pre-chunk uses the largest cap (1000) plus the
     * 1MiB budget so SQLite sub-chunking stays within one transaction.
     *
     * @param list<ValidatedJobShape> $validated Validated job definitions
     * @return list<list<ValidatedJobShape>> Chunks
     */
    private function chunkValidatedJobs(array $validated): array
    {
        $chunks = [];
        $current = [];
        $currentBytes = 0;
        foreach ($validated as $job) {
            $size = strlen($job['encodedPayload']) + strlen($job['queue']) + strlen($job['type']) + 64;
            if ($this->mustStartNewChunk($current, $currentBytes, $size)) {
                $chunks[] = $current;
                $current = [];
                $currentBytes = 0;
            }
            $current[] = $job;
            $currentBytes += $size;
            if ($this->chunkIsFull($current, $currentBytes)) {
                $chunks[] = $current;
                $current = [];
                $currentBytes = 0;
            }
        }
        if ($current !== []) {
            $chunks[] = $current;
        }
        return $chunks;
    }

    /** @param list<ValidatedJobShape> $current */
    private function mustStartNewChunk(array $current, int $currentBytes, int $nextSize): bool
    {
        if ($current === []) {
            return false;
        }
        if ($currentBytes + $nextSize > 1_048_576) {
            return true;
        }
        return count($current) >= 1000;
    }

    /** @param list<ValidatedJobShape> $current */
    private function chunkIsFull(array $current, int $currentBytes): bool
    {
        if (count($current) >= 1000) {
            return true;
        }
        return $currentBytes >= 1_048_576;
    }

    /**
     * Insert one validated chunk and return exact IDs.
     *
     * SQLite chunks are sub-divided to 100 rows so bind limits hold;
     * MySQL/PostgreSQL use 1000-row chunks. All run inside the caller's
     * transaction/savepoint.
     *
     * @param PDO $pdo Active connection (transaction/savepoint already held)
     * @param string $driver PDO driver name
     * @param list<ValidatedJobShape> $chunk Validated jobs
     * @param string $now Creation timestamp
     * @return list<int> Exact inserted IDs in definition order
     */
    private function insertValidatedChunk(PDO $pdo, string $driver, array $chunk, string $now): array
    {
        $context = ['pdo' => $pdo, 'driver' => $driver, 'now' => $now];
        return $this->batchInserter->insert($context, $chunk);
    }

    public function find(int $id): ?JobData
    {
        JobStorageRules::validatePositiveId($id);
        $sql = "SELECT * FROM {$this->table} WHERE id = :id";
        return $this->withReconnect(function (PDO $pdo) use ($sql, $id): ?JobData {
            $stmt = $this->prepare($pdo, $sql);
            $stmt->execute(['id' => $id]);
            return $this->fetchJob($stmt);
        });
    }

    public function findActiveByRequestId(string $requestId): ?JobData
    {
        if (trim($requestId) === '') {
            throw new \InvalidArgumentException('Request ID must not be empty');
        }
        JobStorageRules::validateBoundedString($requestId, 'Request ID');
        $sql = "SELECT * FROM {$this->table}
            WHERE request_id = :request_id
            AND status IN ('pending', 'running')
            LIMIT 1";

        return $this->withReconnect(function (PDO $pdo) use ($sql, $requestId): ?JobData {
            $stmt = $this->prepare($pdo, $sql);
            $stmt->execute(['request_id' => $requestId]);
            return $this->fetchJob($stmt);
        });
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
        $request = compact('type', 'payload', 'requestId', 'queue', 'maxAttempts');
        // The database conditional/generated unique index is the concurrency authority.
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $result = $this->attemptCreateIdempotentJob($request);
            if ($result !== null) {
                return $result;
            }
        }

        throw new \RuntimeException('Could not resolve concurrent idempotent job creation');
    }

    /** @param IdempotentRequest $request */
    private function attemptCreateIdempotentJob(array $request): ?IdempotentJobResult
    {
        $existing = $this->findActiveByRequestId($request['requestId']);
        if ($existing !== null) {
            return new IdempotentJobResult($existing->id, false);
        }

        $pdo = $this->getPdo();
        $hasSavepoint = $pdo->inTransaction();
        if ($hasSavepoint) {
            $pdo->exec('SAVEPOINT simplequeue_idempotent_job');
        }

        try {
            return $this->createIdempotentWithinSavepoint($request, $pdo, $hasSavepoint);
        } catch (IndeterminateStorageOutcomeException $exception) {
            return $this->resolveIndeterminateIdempotentResult($request, $exception, $hasSavepoint);
        } catch (PDOException $exception) {
            return $this->resolveUniqueIdempotentResult($request, $exception, $pdo, $hasSavepoint);
        }
    }

    /** @param IdempotentRequest $request */
    private function createIdempotentWithinSavepoint(
        array $request,
        PDO $pdo,
        bool $hasSavepoint
    ): IdempotentJobResult {
        $id = $this->createJob(
            $request['type'],
            $request['payload'],
            $request['queue'],
            $request['maxAttempts'],
            $request['requestId']
        );
        if ($hasSavepoint) {
            $pdo->exec('RELEASE SAVEPOINT simplequeue_idempotent_job');
        }
        return new IdempotentJobResult($id, true);
    }

    /** @param IdempotentRequest $request */
    private function resolveIndeterminateIdempotentResult(
        array $request,
        IndeterminateStorageOutcomeException $exception,
        bool $hasSavepoint
    ): IdempotentJobResult {
        // A caller-owned transaction has no independently visible state with which to prove the insert.
        if ($hasSavepoint) {
            throw $exception;
        }
        $existing = $this->findActiveByRequestId($request['requestId']);
        if ($existing === null) {
            throw $exception;
        }
        return new IdempotentJobResult($existing->id, false);
    }

    /** @param IdempotentRequest $request */
    private function resolveUniqueIdempotentResult(
        array $request,
        PDOException $exception,
        PDO $pdo,
        bool $hasSavepoint
    ): ?IdempotentJobResult {
        if (!$this->isUniqueConstraintViolation($exception)) {
            throw $exception;
        }
        $this->rollbackIdempotentSavepoint($pdo, $hasSavepoint);
        $existing = $this->findActiveByRequestId($request['requestId']);
        return $existing === null ? null : new IdempotentJobResult($existing->id, false);
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
        if ($queue !== null) {
            JobStorageRules::validateQueueOrType($queue, 'Queue');
        }
        if ($id !== null) {
            JobStorageRules::validatePositiveId($id);
        }
        JobStorageRules::validateWorkerId($workerId);
        // Claims behave consistently inside caller-owned transactions:
        // reject before any SQL on every driver.
        $pdo = $this->freshPdoForMutation();
        if ($pdo->inTransaction()) {
            throw new \RuntimeException('Job claims are not allowed inside a caller-owned transaction');
        }
        $now = $this->now();
        $leaseToken = $this->generateLeaseToken();

        $claim = function (PDO $pdo) use ($queue, $id, $workerId, $leaseToken, $now): ?ClaimedJob {
            $driverAttr = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            $driver = is_string($driverAttr) ? $driverAttr : '';

            if ($driver === 'pgsql') {
                return $this->claimWithReturning($pdo, $queue, $id, $workerId, $leaseToken, $now);
            }

            $context = compact('pdo', 'driver', 'queue', 'id', 'workerId', 'leaseToken', 'now');
            return $this->claimJobWithTransaction($context);
        };

        return $this->withMutation('claimJob', $claim);
    }

    public function markCompleted(ClaimedJob $claim, mixed $result = null): bool
    {
        $now = $this->now();
        // Encode before any mutation so serialization failure never touches storage.
        $encodedResult = $result === null ? null : JobStorageRules::encodeJson($result, 'job result');

        $stmt = $this->executeClaimUpdate($claim, "status = 'completed',
            result = :result, completed_at = :completed_at,
            error_message = NULL, error_trace = NULL,
            progress = NULL, progress_message = NULL,
            locked_by = NULL, locked_at = NULL, lease_token = NULL,
            updated_at = :updated_at", [
            'result' => $encodedResult,
            'completed_at' => $now,
            'updated_at' => $now,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function markFailed(ClaimedJob $claim, string $errorMessage, ?string $errorTrace = null): bool
    {
        $now = $this->now();

        // Canonical terminal failure consumes one failed execution.
        $stmt = $this->executeClaimUpdate($claim, "status = 'failed',
            attempts = attempts + 1,
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
        JobStorageRules::validateProgressUpdate($progress, $message);
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
        JobStorageRules::validateRetry($attempts, $delaySeconds, $claim->job->maxAttempts);
        $now = $this->now();
        $availableAt = JobStorageRules::timestamp($this->clock(), $this->dateFormat, $delaySeconds);

        // Canonical retry: consume failure, reset result/completion/progress.
        $stmt = $this->executeClaimUpdate($claim, "status = 'pending',
            attempts = :attempts, available_at = :available_at,
            error_message = :error_message, result = NULL, completed_at = NULL,
            progress = NULL, progress_message = NULL,
            locked_by = NULL, locked_at = NULL,
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
        return $this->staleJobRecovery->recoverAll($ttlSeconds);
    }

    public function recoverStaleJobsForQueue(string $queue, int $ttlSeconds, int $limit): int
    {
        return $this->staleJobRecovery->recoverQueue($queue, $ttlSeconds, $limit);
    }

    /**
     * @param array{sql: string, params: array<string, mixed>, limit: int} $request
     * @return list<array<string, mixed>>
     */
    private function staleRows(array $request): array
    {
        return $this->withReconnect(function (PDO $pdo) use ($request): array {
            $statement = $this->boundedStatement($pdo, $request['sql'], $request['params'], $request['limit']);
            /** @var list<array<string, mixed>> $rows */
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            return $rows;
        });
    }

    /**
     * @param array{sql: string, params: array<string, mixed>, operation: string} $request
     * @return PDOStatement Executed mutation
     */
    private function executeStaleMutation(array $request): PDOStatement
    {
        return $this->executeMutation($request['sql'], $request['params'], $request['operation']);
    }

    public function cancel(int $id): bool
    {
        JobStorageRules::validatePositiveId($id);
        $now = $this->now();
        $sql = "UPDATE {$this->table}
            SET status = 'cancelled', completed_at = :completed_at,
                locked_by = NULL, locked_at = NULL, lease_token = NULL, updated_at = :updated_at
            WHERE id = :id AND status = 'pending'";

        $stmt = $this->executeMutation($sql, [
            'id' => $id,
            'completed_at' => $now,
            'updated_at' => $now,
        ], 'cancel');

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
        JobStorageRules::validatePositiveLimit($limit, 'List limit');
        JobStorageRules::validateNonNegative($offset, 'List offset');
        if ($queue !== null) {
            JobStorageRules::validateQueueOrType($queue, 'Queue');
        }
        [$sql, $params] = $this->filteredQuery('SELECT *', new JobFilter($status, $queue));
        $sql .= " ORDER BY id DESC LIMIT :limit OFFSET :offset";

        return $this->withReconnect(function (PDO $pdo) use ($sql, $params, $limit, $offset): array {
            return $this->fetchJobs($this->boundedStatement($pdo, $sql, $params, $limit, $offset));
        });
    }

    /**
     * @return list<JobData>
     */
    public function scanPending(string $queue, ?int $afterId, int $limit): array
    {
        JobStorageRules::validateQueueOrType($queue, 'Queue');
        JobStorageRules::validatePositiveLimit($limit, 'Scan limit');
        if ($afterId !== null) {
            JobStorageRules::validatePositiveId($afterId);
        }
        $sql = "SELECT * FROM {$this->table} WHERE queue = :queue AND status = 'pending'";
        $params = ['queue' => $queue];
        if ($afterId !== null) {
            $sql .= ' AND id > :after_id';
            $params['after_id'] = $afterId;
        }
        $sql .= ' ORDER BY id ASC LIMIT :limit';
        return $this->withReconnect(function (PDO $pdo) use ($sql, $params, $limit): array {
            return $this->fetchJobs($this->boundedStatement($pdo, $sql, $params, $limit));
        });
    }

    /**
     * Scan pending notifications without decoding payload/result JSON.
     *
     * @param string $queue Queue name
     * @param int|null $afterId Exclusive keyset cursor
     * @param int $limit Maximum rows to return
     * @return list<PendingNotification> Pending projections in ascending ID order
     */
    public function scanPendingNotifications(string $queue, ?int $afterId, int $limit): array
    {
        JobStorageRules::validateQueueOrType($queue, 'Queue');
        JobStorageRules::validatePositiveLimit($limit, 'Scan limit');
        if ($afterId !== null) {
            JobStorageRules::validatePositiveId($afterId);
        }
        $sql = "SELECT id, available_at FROM {$this->table} WHERE queue = :queue AND status = 'pending'";
        $params = ['queue' => $queue];
        if ($afterId !== null) {
            $sql .= ' AND id > :after_id';
            $params['after_id'] = $afterId;
        }
        $sql .= ' ORDER BY id ASC LIMIT :limit';
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->withReconnect(function (PDO $pdo) use ($sql, $params, $limit): array {
            return $this->boundedStatement($pdo, $sql, $params, $limit)->fetchAll(PDO::FETCH_ASSOC);
        });
        $notifications = [];
        foreach ($rows as $row) {
            $id = isset($row['id']) && is_numeric($row['id']) ? (int) $row['id'] : 0;
            if ($id < 1) {
                continue;
            }
            $availableAt = $row['available_at'] ?? null;
            $notifications[] = new PendingNotification($id, is_string($availableAt) ? $availableAt : null);
        }
        return $notifications;
    }

    /**
     * Count jobs by status.
     *
     * @param JobStatus|null $status Filter by status (null for all)
     * @param string|null $queue Filter by queue (null for all)
     */
    public function count(?JobStatus $status = null, ?string $queue = null): int
    {
        if ($queue !== null) {
            JobStorageRules::validateQueueOrType($queue, 'Queue');
        }
        [$sql, $params] = $this->filteredQuery('SELECT COUNT(*) as cnt', new JobFilter($status, $queue));
        return $this->withReconnect(function (PDO $pdo) use ($sql, $params): int {
            $stmt = $this->prepare($pdo, $sql);
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return is_array($row) && isset($row['cnt']) && is_numeric($row['cnt']) ? (int) $row['cnt'] : 0;
        });
    }

    /**
     * Delete completed jobs older than a given number of days.
     *
     * @param int $days Number of days to keep completed jobs
     * @return int Number of deleted jobs
     */
    public function pruneCompleted(int $days = 7): int
    {
        JobStorageRules::validateNonNegative($days, 'Retention days');
        $threshold = JobStorageRules::timestamp($this->clock(), $this->dateFormat, -$days * 86400);

        $sql = "DELETE FROM {$this->table}
            WHERE status IN ('completed', 'cancelled')
            AND completed_at < :threshold";

        $stmt = $this->executeMutation($sql, ['threshold' => $threshold], 'pruneCompleted');

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
        $merged = array_merge($this->claimIdentity($claim), $params);
        // Guard against duplicate placeholder collisions between identity and assignments.
        return $this->executeMutation($sql, $merged, 'fencedUpdate');
    }

    private function claimStillOwned(ClaimedJob $claim): bool
    {
        $sql = "SELECT 1 FROM {$this->table}
            WHERE id = :id AND status = 'running' AND lease_token = :lease_token";
        return $this->withReconnect(function (PDO $pdo) use ($sql, $claim): bool {
            $stmt = $this->prepare($pdo, $sql);
            $stmt->execute($this->claimIdentity($claim));
            return $stmt->fetch() !== false;
        });
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
            fn (array $row): JobData => JobDataHydrator::hydrateStrict($this->associativeRow($row)),
            $statement->fetchAll(PDO::FETCH_ASSOC)
        ));
    }

    private function fetchJob(PDOStatement $statement): ?JobData
    {
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? JobDataHydrator::hydrateStrict($this->associativeRow($row)) : null;
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

    /** @param ClaimContext $context */
    private function claimJobWithTransaction(array $context): ?ClaimedJob
    {
        $selection = $this->claimSelection($context);
        if ($context['driver'] !== 'sqlite') {
            $selection['sql'] .= ' FOR UPDATE SKIP LOCKED';
        }

        $transaction = new PdoClaimTransaction($context['pdo'], $context['driver']);
        $transaction->begin();

        try {
            $statement = $this->claimWithinTransaction($context, $selection);
            $this->commitClaim($transaction, $context['pdo']);
            return $statement === null
                ? null
                : $this->claimFromStatement($statement, $context['workerId'], $context['leaseToken']);
        } catch (\Throwable $exception) {
            $this->handleClaimFailure($transaction, $context['pdo'], $exception);
        }
    }

    /**
     * @param ClaimContext $context
     * @return ClaimSelection
     */
    private function claimSelection(array $context): array
    {
        if ($context['id'] !== null) {
            $selectSql = "SELECT * FROM {$this->table}
                WHERE id = :id
                AND (
                    (status = 'pending' AND available_at <= :now)
                    OR (status = 'running' AND locked_by = :worker_id)
                )
                LIMIT 1";
            $selectParams = [
                'id' => $context['id'],
                'now' => $context['now'],
                'worker_id' => $context['workerId'],
            ];
        } else {
            $selectSql = "SELECT * FROM {$this->table}
                WHERE status = 'pending'
                AND queue = :queue
                AND available_at <= :now
                ORDER BY available_at ASC, id ASC
                LIMIT 1";
            $selectParams = ['queue' => $context['queue'], 'now' => $context['now']];
        }
        return ['sql' => $selectSql, 'params' => $selectParams];
    }

    private function commitClaim(PdoClaimTransaction $transaction, PDO $pdo): void
    {
        try {
            $transaction->commit();
        } catch (PDOException $exception) {
            if (!$this->isConnectionException($exception)) {
                throw $exception;
            }
            $this->discardFactoryConnection($pdo);
            throw IndeterminateStorageOutcomeException::forOperation('claimJob', $exception);
        }
    }

    private function handleClaimFailure(
        PdoClaimTransaction $transaction,
        PDO $pdo,
        \Throwable $exception
    ): never {
        $transaction->rollbackIgnoringFailure();
        if (!$exception instanceof PDOException) {
            throw $exception;
        }
        if (!$this->isConnectionException($exception)) {
            throw $exception;
        }
        $this->discardFactoryConnection($pdo);
        throw IndeterminateStorageOutcomeException::forOperation('claimJob', $exception);
    }

    /**
     * @param ClaimContext $context
     * @param ClaimSelection $selection
     */
    private function claimWithinTransaction(
        array $context,
        array $selection
    ): ?PDOStatement {
        $select = $this->prepare($context['pdo'], $selection['sql']);
        $select->execute($selection['params']);
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
        $update = $this->prepare($context['pdo'], $updateSql);
        $update->execute([
            'id' => $id,
            'worker_id' => $context['workerId'],
            'worker_id_where' => $context['workerId'],
            'locked_at' => $context['now'],
            'started_at' => $context['now'],
            'lease_token' => $context['leaseToken'],
            'updated_at' => $context['now'],
            'now' => $context['now'],
        ]);
        if ($update->rowCount() === 0) {
            return null;
        }

        $find = $this->prepare($context['pdo'], "SELECT * FROM {$this->table} WHERE id = :id");
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
        return new ClaimedJob(JobDataHydrator::hydrateStrict($this->associativeRow($row)), $workerId, $leaseToken);
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
