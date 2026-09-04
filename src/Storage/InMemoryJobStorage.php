<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Storage;

use Oeltima\SimpleQueue\Contract\ClaimedJob;
use Oeltima\SimpleQueue\Contract\ClockInterface;
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
use Oeltima\SimpleQueue\Internal\JobDataHydrator;
use Oeltima\SimpleQueue\Internal\JobFilter;
use Oeltima\SimpleQueue\Internal\JobStorageRules;
use Oeltima\SimpleQueue\Internal\RetryDecision;
use Oeltima\SimpleQueue\SystemClock;

/**
 * In-memory job storage for testing purposes.
 *
 * This storage keeps all jobs in memory and is useful for unit testing.
 * All data is lost when the process terminates.
 *
 * @phpstan-type StoredJobRow array{
 *     id: int, queue: string, type: string, status: JobStatus, payload: string,
 *     attempts: int, max_attempts: int, available_at: string, started_at: ?string,
 *     completed_at: ?string, locked_by: ?string, locked_at: ?string, lease_token: ?string,
 *     error_message: ?string, error_trace: ?string, progress: ?int,
 *     progress_message: ?string, result: ?string, request_id: ?string,
 *     created_at: string, updated_at: string
 * }
 * @phpstan-import-type JobDefinitionShape from JobStorageInterface
 */
class InMemoryJobStorage implements
    JobStorageInterface,
    JobStorageAdminInterface,
    SupportsIdempotentJobCreation,
    SupportsFailedJobAdministration,
    SupportsPendingJobCursor,
    SupportsPendingNotificationCursor,
    SupportsQueueScopedStaleRecovery
{
    /** @var array<int, StoredJobRow> */
    private array $jobs = [];
    private int $nextId = 1;
    private string $dateFormat = 'Y-m-d H:i:s';

    public function __construct(
        private readonly ClockInterface $clock = new SystemClock()
    ) {
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
        // Encode before consuming an ID so serialization failure leaves state unchanged.
        $encoded = JobStorageRules::encodeJson($payload, 'job payload');
        $now = $this->clock->now();
        $id = $this->nextId++;

        $this->jobs[$id] = $this->newJobRow($id, $type, $encoded, $queue, $maxAttempts, $requestId, $now, $now, $now);

        return $id;
    }

    /**
     * Build a stored job row shared by the single and batch creation paths.
     *
     * @param int $id Job identifier
     * @param string $type Job type identifier
     * @param string $encodedPayload Pre-encoded JSON payload
     * @param string $queue Queue name
     * @param int $maxAttempts Maximum retry attempts
     * @param string|null $requestId Optional request correlation ID
     * @param string $availableAt UTC timestamp the job becomes available
     * @param string $createdAt UTC creation timestamp
     * @param string $updatedAt UTC update timestamp
     * @return StoredJobRow
     */
    private function newJobRow(
        int $id,
        string $type,
        string $encodedPayload,
        string $queue,
        int $maxAttempts,
        ?string $requestId,
        string $availableAt,
        string $createdAt,
        string $updatedAt
    ): array {
        return [
            'id' => $id,
            'queue' => $queue,
            'type' => $type,
            'status' => JobStatus::Pending,
            'payload' => $encodedPayload,
            'attempts' => 0,
            'max_attempts' => $maxAttempts,
            'available_at' => $availableAt,
            'started_at' => null,
            'completed_at' => null,
            'locked_by' => null,
            'locked_at' => null,
            'lease_token' => null,
            'error_message' => null,
            'error_trace' => null,
            'progress' => null,
            'progress_message' => null,
            'result' => null,
            'request_id' => $requestId,
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
        ];
    }

    /**
     * Batch create multiple job records atomically.
     *
     * The complete batch is validated (including JSON serialization) before
     * any row is added or ID consumed, so invalid input changes neither
     * rows, notifications, nor next ID.
     *
     * @param array<int, JobDefinitionShape> $jobs Array of job definitions
     * @return int[] Array of created job IDs
     */
    public function createJobs(array $jobs): array
    {
        if ($jobs === []) {
            return [];
        }
        // Validate complete batch first; never partially commit or consume IDs.
        $validated = [];
        foreach ($jobs as $job) {
            $validated[] = JobStorageRules::validateJobDefinition($job, $this->clock);
        }
        $now = $this->clock->now();
        $ids = [];
        foreach ($validated as $definition) {
            $id = $this->nextId++;
            $this->jobs[$id] = $this->newJobRow(
                $id,
                $definition['type'],
                $definition['encodedPayload'],
                $definition['queue'],
                $definition['maxAttempts'],
                $definition['requestId'],
                $definition['availableAt'],
                $now,
                $now
            );
            $ids[] = $id;
        }
        return $ids;
    }

    public function find(int $id): ?JobData
    {
        JobStorageRules::validatePositiveId($id);
        if (!isset($this->jobs[$id])) {
            return null;
        }

        return JobDataHydrator::hydrateStrict($this->jobs[$id]);
    }

    public function findActiveByRequestId(string $requestId): ?JobData
    {
        if (trim($requestId) === '') {
            throw new \InvalidArgumentException('Request ID must not be empty');
        }
        JobStorageRules::validateBoundedString($requestId, 'Request ID');
        foreach ($this->jobs as $job) {
            if (
                $job['request_id'] === $requestId
                && in_array($job['status'], [JobStatus::Pending, JobStatus::Running], true)
            ) {
                return JobDataHydrator::hydrateStrict($job);
            }
        }

        return null;
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
        $existing = $this->findActiveByRequestId($requestId);
        if ($existing !== null) {
            return new IdempotentJobResult($existing->id, false);
        }

        return new IdempotentJobResult($this->createJob($type, $payload, $queue, $maxAttempts, $requestId), true);
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
        JobStorageRules::validateQueueOrType($queue, 'Queue');
        JobStorageRules::validateWorkerId($workerId);
        $now = $this->clock->now();
        $candidateId = null;
        $candidateAvailableAt = null;

        foreach ($this->jobs as $id => $job) {
            if ($job['status'] !== JobStatus::Pending || $job['queue'] !== $queue || $job['available_at'] > $now) {
                continue;
            }
            if ($this->isBetterCandidate($job, $id, $candidateAvailableAt, $candidateId)) {
                $candidateId = $id;
                $candidateAvailableAt = $job['available_at'];
            }
        }

        if ($candidateId === null) {
            return null;
        }

        return $this->claimAvailableJob($candidateId, $workerId, $now);
    }

    /**
     * @param array<string, mixed> $job Storage job row
     */
    private function isBetterCandidate(array $job, int $id, ?string $candidateAvailableAt, ?int $candidateId): bool
    {
        return $candidateAvailableAt === null
            || $job['available_at'] < $candidateAvailableAt
            || ($job['available_at'] === $candidateAvailableAt && $id < (int) $candidateId);
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
        JobStorageRules::validateWorkerId($workerId);
        return $this->claimAvailableJob($id, $workerId, $this->clock->now());
    }

    public function markCompleted(ClaimedJob $claim, mixed $result = null): bool
    {
        $encodedResult = $result === null ? null : JobStorageRules::encodeJson($result, 'job result');
        if (!$this->ownsClaim($claim)) {
            return false;
        }

        $now = $this->clock->now();
        $job = &$this->jobs[$claim->job->id];
        $job['status'] = JobStatus::Completed;
        $job['result'] = $encodedResult;
        $job['completed_at'] = $now;
        $job['error_message'] = null;
        $job['error_trace'] = null;
        $job['progress'] = null;
        $job['progress_message'] = null;
        $this->releaseClaim($job, $now);

        return true;
    }

    public function markFailed(ClaimedJob $claim, string $errorMessage, ?string $errorTrace = null): bool
    {
        if (!$this->ownsClaim($claim)) {
            return false;
        }

        $now = $this->clock->now();
        $job = &$this->jobs[$claim->job->id];
        $job['status'] = JobStatus::Failed;
        $job['attempts']++;
        $job['error_message'] = $errorMessage;
        $job['error_trace'] = $errorTrace;
        $job['completed_at'] = $now;
        // Retain last progress for diagnosis on terminal failure.
        $this->releaseClaim($job, $now);

        return true;
    }

    public function updateProgress(ClaimedJob $claim, ?int $progress = null, ?string $message = null): bool
    {
        JobStorageRules::validateProgressUpdate($progress, $message);
        if (!$this->ownsClaim($claim)) {
            return false;
        }

        $job = &$this->jobs[$claim->job->id];
        $job['progress'] = $progress;
        $job['progress_message'] = $message;
        $job['locked_at'] = $this->clock->now();
        $job['updated_at'] = $job['locked_at'];

        return true;
    }

    public function scheduleRetry(
        ClaimedJob $claim,
        int $attempts,
        int $delaySeconds,
        ?string $errorMessage = null
    ): bool {
        JobStorageRules::validateRetry($attempts, $delaySeconds, $claim->job->maxAttempts);
        if (!$this->ownsClaim($claim)) {
            return false;
        }

        $now = $this->clock->now();
        $availableAt = JobStorageRules::timestamp($this->clock, $this->dateFormat, $delaySeconds);

        $job = &$this->jobs[$claim->job->id];
        $job['status'] = JobStatus::Pending;
        $job['attempts'] = $attempts;
        $job['available_at'] = $availableAt;
        $job['error_message'] = $errorMessage;
        $job['result'] = null;
        $job['completed_at'] = null;
        $job['progress'] = null;
        $job['progress_message'] = null;
        $this->releaseClaim($job, $now);

        return true;
    }

    public function heartbeat(ClaimedJob $claim): bool
    {
        if (!$this->ownsClaim($claim)) {
            return false;
        }

        $now = $this->clock->now();
        $job = &$this->jobs[$claim->job->id];
        $job['locked_at'] = $now;
        $job['updated_at'] = $now;

        return true;
    }

    public function recoverStaleJobs(int $ttlSeconds): int
    {
        JobStorageRules::validateStaleRecovery($ttlSeconds, 1);
        $now = $this->clock->now();
        $staleThreshold = JobStorageRules::timestamp($this->clock, $this->dateFormat, -$ttlSeconds);
        $staleError = 'Job timed out / worker crashed (stale recovery)';
        $count = 0;

        foreach ($this->jobs as &$job) {
            if ($job['status'] !== JobStatus::Running) {
                continue;
            }
            if ($job['locked_at'] !== null && $job['locked_at'] >= $staleThreshold) {
                continue;
            }

            $this->recoverStaleJob($job, $now, $staleError);
            $count++;
        }

        return $count;
    }

    public function recoverStaleJobsForQueue(string $queue, int $ttlSeconds, int $limit): int
    {
        JobStorageRules::validateQueueOrType($queue, 'Queue');
        JobStorageRules::validateStaleRecovery($ttlSeconds, $limit);
        $now = $this->clock->now();
        $threshold = JobStorageRules::timestamp($this->clock, $this->dateFormat, -$ttlSeconds);
        $staleError = 'Job timed out / worker crashed (stale recovery)';
        // Match PDO ordering (locked_at ASC) and null-lock handling.
        $candidates = [];
        foreach ($this->jobs as $id => $job) {
            if ($job['queue'] !== $queue || $job['status'] !== JobStatus::Running) {
                continue;
            }
            if ($job['locked_at'] !== null && $job['locked_at'] >= $threshold) {
                continue;
            }
            $candidates[] = ['id' => $id, 'locked_at' => $job['locked_at']];
        }
        usort($candidates, static function (array $a, array $b): int {
            $lockedOrder = ($a['locked_at'] ?? '') <=> ($b['locked_at'] ?? '');
            return $lockedOrder !== 0 ? $lockedOrder : $a['id'] <=> $b['id'];
        });
        $recovered = 0;
        foreach ($candidates as $candidate) {
            if ($recovered >= $limit) {
                break;
            }
            $id = $candidate['id'];
            $job = &$this->jobs[$id];
            $this->recoverStaleJob($job, $now, $staleError);
            $recovered++;
        }
        unset($job);
        return $recovered;
    }

    /** @param StoredJobRow $job */
    private function recoverStaleJob(array &$job, string $now, string $staleError): void
    {
        $job['attempts']++;
        $job['error_message'] = $staleError;
        $job['available_at'] = $now;
        if (RetryDecision::forAttempt($job['attempts'], $job['max_attempts'])->shouldRetry()) {
            $job['status'] = JobStatus::Pending;
            $job['result'] = null;
            $job['completed_at'] = null;
            $job['progress'] = null;
            $job['progress_message'] = null;
        } else {
            $job['status'] = JobStatus::Failed;
            $job['completed_at'] = $now;
        }
        $this->releaseClaim($job, $now);
    }

    public function requeueFailed(int $jobId): ?JobData
    {
        JobStorageRules::validatePositiveId($jobId);
        if (!isset($this->jobs[$jobId]) || $this->jobs[$jobId]['status'] !== JobStatus::Failed) {
            return null;
        }

        $now = $this->clock->now();
        $job = &$this->jobs[$jobId];
        $job['status'] = JobStatus::Pending;
        $job['attempts'] = 0;
        $job['available_at'] = $now;
        $job['started_at'] = null;
        $job['completed_at'] = null;
        $job['error_message'] = null;
        $job['error_trace'] = null;
        $job['progress'] = null;
        $job['progress_message'] = null;
        $job['result'] = null;
        $this->releaseClaim($job, $now);

        return JobDataHydrator::hydrateStrict($job);
    }

    public function purgeFailed(int $jobId): ?JobData
    {
        JobStorageRules::validatePositiveId($jobId);
        if (!isset($this->jobs[$jobId]) || $this->jobs[$jobId]['status'] !== JobStatus::Failed) {
            return null;
        }
        $job = JobDataHydrator::hydrateStrict($this->jobs[$jobId]);
        unset($this->jobs[$jobId]);
        return $job;
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
        $filter = new JobFilter($status, $queue);
        $filtered = array_filter(
            $this->jobs,
            static fn (array $job): bool => $filter->matches($job['status'], $job['queue'])
        );

        $filtered = array_reverse($filtered, true);
        $filtered = array_slice($filtered, $offset, $limit, true);

        return array_values(array_map(fn($job) => JobDataHydrator::hydrateStrict($job), $filtered));
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
        $jobs = [];
        foreach ($this->jobs as $id => $job) {
            if (
                $job['status'] !== JobStatus::Pending
                || $job['queue'] !== $queue
                || ($afterId !== null && $id <= $afterId)
            ) {
                continue;
            }
            $jobs[] = JobDataHydrator::hydrateStrict($job);
            if (count($jobs) === $limit) {
                break;
            }
        }
        return $jobs;
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
        $notifications = [];
        $ids = array_keys($this->jobs);
        sort($ids);
        foreach ($ids as $id) {
            if ($afterId !== null && $id <= $afterId) {
                continue;
            }
            $job = $this->jobs[$id];
            if ($job['status'] !== JobStatus::Pending || $job['queue'] !== $queue) {
                continue;
            }
            $notifications[] = new PendingNotification($id, $job['available_at']);
            if (count($notifications) >= $limit) {
                break;
            }
        }
        return $notifications;
    }

    public function count(?JobStatus $status = null, ?string $queue = null): int
    {
        if ($queue !== null) {
            JobStorageRules::validateQueueOrType($queue, 'Queue');
        }
        $filter = new JobFilter($status, $queue);
        $count = 0;

        foreach ($this->jobs as $job) {
            if (!$filter->matches($job['status'], $job['queue'])) {
                continue;
            }
            $count++;
        }

        return $count;
    }

    public function pruneCompleted(int $days = 7): int
    {
        JobStorageRules::validateNonNegative($days, 'Retention days');
        $threshold = JobStorageRules::timestamp($this->clock, $this->dateFormat, -$days * 86400);
        $count = 0;

        foreach ($this->jobs as $id => $job) {
            if (!in_array($job['status'], [JobStatus::Completed, JobStatus::Cancelled], true)) {
                continue;
            }
            if ($job['completed_at'] === null || $job['completed_at'] >= $threshold) {
                continue;
            }
            unset($this->jobs[$id]);
            $count++;
        }

        return $count;
    }
    /**
     * Get all jobs (for testing).
     *
     * @return JobData[]
     */
    public function all(): array
    {
        return array_map(fn($job) => JobDataHydrator::hydrateStrict($job), $this->jobs);
    }

    /**
     * Clear all jobs (for testing).
     */
    public function clear(): void
    {
        $this->jobs = [];
        $this->nextId = 1;
    }

    public function cancel(int $id): bool
    {
        JobStorageRules::validatePositiveId($id);
        if (!isset($this->jobs[$id])) {
            return false;
        }

        if ($this->jobs[$id]['status'] !== JobStatus::Pending) {
            return false;
        }

        $now = $this->clock->now();
        $job = &$this->jobs[$id];
        $job['status'] = JobStatus::Cancelled;
        $job['completed_at'] = $now;
        $this->releaseClaim($job, $now);

        return true;
    }

    private function claimAvailableJob(int $id, string $workerId, string $now): ?ClaimedJob
    {
        JobStorageRules::validatePositiveId($id);
        if (!isset($this->jobs[$id])) {
            return null;
        }

        $job = &$this->jobs[$id];
        $isPending = $job['status'] === JobStatus::Pending && $job['available_at'] <= $now;
        $isAlreadyLockedByMe = $job['status'] === JobStatus::Running && $job['locked_by'] === $workerId;

        if (!$isPending && !$isAlreadyLockedByMe) {
            return null;
        }

        $leaseToken = bin2hex(random_bytes(32));
        $job['status'] = JobStatus::Running;
        $job['locked_by'] = $workerId;
        $job['locked_at'] = $now;
        $job['started_at'] = $now;
        $job['lease_token'] = $leaseToken;
        $job['updated_at'] = $now;

        return new ClaimedJob(JobDataHydrator::hydrateStrict($this->jobs[$id]), $workerId, $leaseToken);
    }

    /** @param StoredJobRow $job */
    private function releaseClaim(array &$job, string $now): void
    {
        $job['locked_by'] = null;
        $job['locked_at'] = null;
        $job['lease_token'] = null;
        $job['updated_at'] = $now;
    }

    private function ownsClaim(ClaimedJob $claim): bool
    {
        $id = $claim->job->id;

        return isset($this->jobs[$id])
            && $this->jobs[$id]['status'] === JobStatus::Running
            && $this->jobs[$id]['lease_token'] === $claim->leaseToken;
    }
}
