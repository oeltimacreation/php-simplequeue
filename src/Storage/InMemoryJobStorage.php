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
use Oeltima\SimpleQueue\Internal\InMemoryFailedJobAdministration;
use Oeltima\SimpleQueue\Internal\JobStorageRules;
use Oeltima\SimpleQueue\Internal\RetryDecision;
use Oeltima\SimpleQueue\SystemClock;

/**
 * In-memory job storage for testing purposes.
 *
 * This storage keeps all jobs in memory and is useful for unit testing.
 * All data is lost when the process terminates.
 *
 * @phpstan-import-type StoredJobRow from \Oeltima\SimpleQueue\Internal\InMemoryJobRow
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
    use InMemoryFailedJobAdministration;

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
        $now = $this->now();
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
        $now = $this->now();
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
        $now = $this->now();
        $candidateId = null;
        $candidateAvailableAt = null;

        foreach ($this->jobs as $id => $job) {
            if (!$this->isClaimableCandidate($job, $queue, $now)) {
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
    private function isClaimableCandidate(array $job, string $queue, string $now): bool
    {
        return $job['status'] === JobStatus::Pending
            && $job['queue'] === $queue
            && $job['available_at'] <= $now;
    }

    /**
     * @param array<string, mixed> $job Storage job row
     */
    private function isBetterCandidate(array $job, int $id, ?string $candidateAvailableAt, ?int $candidateId): bool
    {
        if ($candidateAvailableAt === null) {
            return true;
        }
        if ($job['available_at'] < $candidateAvailableAt) {
            return true;
        }
        return $job['available_at'] === $candidateAvailableAt && $id < (int) $candidateId;
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
        return $this->claimAvailableJob($id, $workerId, $this->now());
    }

    public function markCompleted(ClaimedJob $claim, mixed $result = null): bool
    {
        if (!$this->ownsClaim($claim)) {
            return false;
        }

        $encodedResult = $result === null ? null : JobStorageRules::encodeJson($result, 'job result');
        $now = $this->now();
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

        $now = $this->now();
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
        JobStorageRules::validateProgress($progress);
        if (!$this->ownsClaim($claim)) {
            return false;
        }

        $job = &$this->jobs[$claim->job->id];
        $job['progress'] = $progress;
        $job['progress_message'] = $message;
        $job['locked_at'] = $this->now();
        $job['updated_at'] = $job['locked_at'];

        return true;
    }

    public function scheduleRetry(
        ClaimedJob $claim,
        int $attempts,
        int $delaySeconds,
        ?string $errorMessage = null
    ): bool {
        JobStorageRules::validateRetry($attempts, $delaySeconds);
        if (!$this->ownsClaim($claim)) {
            return false;
        }

        $now = $this->now();
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

        $now = $this->now();
        $job = &$this->jobs[$claim->job->id];
        $job['locked_at'] = $now;
        $job['updated_at'] = $now;

        return true;
    }

    public function recoverStaleJobs(int $ttlSeconds): int
    {
        JobStorageRules::validateStaleRecovery($ttlSeconds, 1);
        $now = $this->now();
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

            $attempts = $job['attempts'];
            $maxAttempts = $job['max_attempts'];
            $nextAttempts = $attempts + 1;
            if (!RetryDecision::forAttempt($nextAttempts, $maxAttempts)->shouldRetry()) {
                $job['status'] = JobStatus::Failed;
                $job['attempts'] = $nextAttempts;
                $job['error_message'] = $staleError;
                $job['completed_at'] = $now;
                $job['available_at'] = $now;
            } else {
                $job['status'] = JobStatus::Pending;
                $job['attempts'] = $nextAttempts;
                $job['error_message'] = $staleError;
                $job['result'] = null;
                $job['completed_at'] = null;
                $job['progress'] = null;
                $job['progress_message'] = null;
                $job['available_at'] = $now;
            }

            $this->releaseClaim($job, $now);
            $count++;
        }

        return $count;
    }

    public function recoverStaleJobsForQueue(string $queue, int $ttlSeconds, int $limit): int
    {
        JobStorageRules::validateQueueOrType($queue, 'Queue');
        JobStorageRules::validateStaleRecovery($ttlSeconds, $limit);
        $now = $this->now();
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
        usort($candidates, static fn (array $a, array $b): int => ($a['locked_at'] ?? '') <=> ($b['locked_at'] ?? ''));
        $recovered = 0;
        foreach ($candidates as $candidate) {
            if ($recovered >= $limit) {
                break;
            }
            $id = $candidate['id'];
            $job = &$this->jobs[$id];
            $nextAttempts = $job['attempts'] + 1;
            if (!RetryDecision::forAttempt($nextAttempts, $job['max_attempts'])->shouldRetry()) {
                $job['status'] = JobStatus::Failed;
                $job['attempts'] = $nextAttempts;
                $job['error_message'] = $staleError;
                $job['completed_at'] = $now;
                $job['available_at'] = $now;
            } else {
                $job['status'] = JobStatus::Pending;
                $job['attempts'] = $nextAttempts;
                $job['error_message'] = $staleError;
                $job['result'] = null;
                $job['completed_at'] = null;
                $job['progress'] = null;
                $job['progress_message'] = null;
                $job['available_at'] = $now;
            }
            $this->releaseClaim($job, $now);
            $recovered++;
        }
        unset($job);
        return $recovered;
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
            if (!$this->isPendingAfter($job, $queue, $id, $afterId)) {
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

        $now = $this->now();
        $job = &$this->jobs[$id];
        $job['status'] = JobStatus::Cancelled;
        $job['completed_at'] = $now;
        $this->releaseClaim($job, $now);

        return true;
    }

    private function now(): string
    {
        return $this->clock->now();
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

        $leaseToken = $this->generateLeaseToken();
        $job['status'] = JobStatus::Running;
        $job['locked_by'] = $workerId;
        $job['locked_at'] = $now;
        $job['started_at'] = $now;
        $job['lease_token'] = $leaseToken;
        $job['updated_at'] = $now;

        return new ClaimedJob(JobDataHydrator::hydrateStrict($this->jobs[$id]), $workerId, $leaseToken);
    }

    private function generateLeaseToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /** @param StoredJobRow $job */
    private function releaseClaim(array &$job, string $now): void
    {
        $job['locked_by'] = null;
        $job['locked_at'] = null;
        $job['lease_token'] = null;
        $job['updated_at'] = $now;
    }

    /** @param StoredJobRow $job */
    private function isPendingAfter(array $job, string $queue, int $id, ?int $afterId): bool
    {
        return $job['status'] === JobStatus::Pending
            && $job['queue'] === $queue
            && ($afterId === null || $id > $afterId);
    }

    private function ownsClaim(ClaimedJob $claim): bool
    {
        $id = $claim->job->id;

        return isset($this->jobs[$id])
            && $this->jobs[$id]['status'] === JobStatus::Running
            && $this->jobs[$id]['lease_token'] === $claim->leaseToken;
    }
}
