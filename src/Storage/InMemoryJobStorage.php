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
use Oeltima\SimpleQueue\Contract\SupportsPendingJobCursor;
use Oeltima\SimpleQueue\Contract\SupportsQueueScopedStaleRecovery;
use Oeltima\SimpleQueue\Exception\SerializationException;
use Oeltima\SimpleQueue\SystemClock;

/**
 * In-memory job storage for testing purposes.
 *
 * This storage keeps all jobs in memory and is useful for unit testing.
 * All data is lost when the process terminates.
 *
 * @phpstan-import-type StoredJobRow from \Oeltima\SimpleQueue\Internal\InMemoryJobRow
 */
class InMemoryJobStorage implements
    JobStorageInterface,
    JobStorageAdminInterface,
    SupportsIdempotentJobCreation,
    SupportsPendingJobCursor,
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
        $now = $this->now();
        $id = $this->nextId++;

        $this->jobs[$id] = [
            'id' => $id,
            'queue' => $queue,
            'type' => $type,
            'status' => JobStatus::Pending,
            'payload' => $this->encodeJson($payload, 'job payload'),
            'attempts' => 0,
            'max_attempts' => $maxAttempts,
            'available_at' => $now,
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
            'created_at' => $now,
            'updated_at' => $now,
        ];

        return $id;
    }

    /**
     * Batch create multiple job records in a single operation.
     *
     * @param array<int, array<string, mixed>> $jobs Array of job definitions
     * @return int[] Array of created job IDs
     */
    public function createJobs(array $jobs): array
    {
        $ids = [];
        foreach ($jobs as $job) {
            $type = is_string($job['type'] ?? null) ? $job['type'] : '';
            $payloadRaw = $job['payload'] ?? [];
            /** @var array<string, mixed> $payload */
            $payload = is_array($payloadRaw) ? $payloadRaw : [];
            $queue = isset($job['queue']) && is_string($job['queue']) ? $job['queue'] : 'default';
            $maxAttempts = isset($job['maxAttempts']) && is_numeric($job['maxAttempts'])
                ? (int) $job['maxAttempts']
                : 3;
            $requestId = isset($job['requestId']) && is_string($job['requestId']) ? $job['requestId'] : null;
            $ids[] = $this->createJob($type, $payload, $queue, $maxAttempts, $requestId);
        }
        return $ids;
    }

    public function find(int $id): ?JobData
    {
        if (!isset($this->jobs[$id])) {
            return null;
        }

        return JobData::fromRaw($this->jobs[$id]);
    }

    public function findActiveByRequestId(string $requestId): ?JobData
    {
        foreach ($this->jobs as $job) {
            if (
                $job['request_id'] === $requestId
                && in_array($job['status'], [JobStatus::Pending, JobStatus::Running], true)
            ) {
                return JobData::fromRaw($job);
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
            if ($job['status'] !== JobStatus::Pending) {
                continue;
            }
            if ($job['queue'] !== $queue) {
                continue;
            }
            if ($job['available_at'] > $now) {
                continue;
            }
            if (
                $candidateAvailableAt !== null
                && ($job['available_at'] > $candidateAvailableAt
                    || ($job['available_at'] === $candidateAvailableAt && $id > (int) $candidateId))
            ) {
                continue;
            }

            $candidateId = $id;
            $candidateAvailableAt = $job['available_at'];
        }

        if ($candidateId === null) {
            return null;
        }

        return $this->claimAvailableJob($candidateId, $workerId, $now);
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

        $now = $this->now();
        $job = &$this->jobs[$claim->job->id];
        $job['status'] = JobStatus::Completed;
        $job['result'] = $result === null ? null : $this->encodeJson($result, 'job result');
        $job['completed_at'] = $now;
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
        $job['error_message'] = $errorMessage;
        $job['error_trace'] = $errorTrace;
        $job['completed_at'] = $now;
        $this->releaseClaim($job, $now);

        return true;
    }

    public function updateProgress(ClaimedJob $claim, ?int $progress = null, ?string $message = null): bool
    {
        if ($progress !== null && ($progress < 0 || $progress > 100)) {
            throw new \InvalidArgumentException('Progress must be null or an integer between 0 and 100');
        }
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
        if ($attempts < 1 || $delaySeconds < 0) {
            throw new \InvalidArgumentException('Attempts must be positive and retry delay must not be negative');
        }
        if (!$this->ownsClaim($claim)) {
            return false;
        }

        $now = $this->now();
        $availableAt = gmdate($this->dateFormat, $this->clock->timestamp() + $delaySeconds);

        $job = &$this->jobs[$claim->job->id];
        $job['status'] = JobStatus::Pending;
        $job['attempts'] = $attempts;
        $job['available_at'] = $availableAt;
        $job['error_message'] = $errorMessage;
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
        $now = $this->now();
        $staleThreshold = gmdate($this->dateFormat, $this->clock->timestamp() - $ttlSeconds);
        $count = 0;

        foreach ($this->jobs as &$job) {
            if ($job['status'] !== JobStatus::Running) {
                continue;
            }
            if ($job['locked_at'] === null || $job['locked_at'] >= $staleThreshold) {
                continue;
            }

            $attempts = $job['attempts'];
            $maxAttempts = $job['max_attempts'];
            $nextAttempts = $attempts + 1;
            if ($nextAttempts >= $maxAttempts) {
                $job['status'] = JobStatus::Failed;
                $job['error_message'] = 'Job timed out / worker crashed (stale recovery)';
                $job['completed_at'] = $now;
            } else {
                $job['status'] = JobStatus::Pending;
                $job['attempts'] = $nextAttempts;
                $job['available_at'] = $now;
            }

            $this->releaseClaim($job, $now);
            $count++;
        }

        return $count;
    }

    public function recoverStaleJobsForQueue(string $queue, int $ttlSeconds, int $limit): int
    {
        if ($ttlSeconds < 1 || $limit < 1) {
            throw new \InvalidArgumentException('Stale recovery TTL and limit must be positive');
        }
        $now = $this->now();
        $threshold = gmdate($this->dateFormat, $this->clock->timestamp() - $ttlSeconds);
        $recovered = 0;
        foreach ($this->jobs as &$job) {
            if (
                $recovered === $limit
                || $job['queue'] !== $queue
                || $job['status'] !== JobStatus::Running
                || ($job['locked_at'] !== null && $job['locked_at'] >= $threshold)
            ) {
                continue;
            }
            $job['status'] = JobStatus::Pending;
            $job['attempts']++;
            $job['available_at'] = $now;
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
        $filtered = array_filter($this->jobs, function (array $job) use ($status, $queue): bool {
            if ($status !== null && $job['status'] !== $status) {
                return false;
            }
            if ($queue !== null && $job['queue'] !== $queue) {
                return false;
            }
            return true;
        });

        $filtered = array_reverse($filtered, true);
        $filtered = array_slice($filtered, $offset, $limit, true);

        return array_values(array_map(fn($job) => JobData::fromRaw($job), $filtered));
    }

    /**
     * @return list<JobData>
     */
    public function scanPending(string $queue, ?int $afterId, int $limit): array
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('Scan limit must be positive');
        }
        $jobs = [];
        foreach ($this->jobs as $id => $job) {
            if (!$this->isPendingAfter($job, $queue, $id, $afterId)) {
                continue;
            }
            $jobs[] = JobData::fromRaw($job);
            if (count($jobs) === $limit) {
                break;
            }
        }
        return $jobs;
    }

    public function count(?JobStatus $status = null, ?string $queue = null): int
    {
        $count = 0;

        foreach ($this->jobs as $job) {
            if ($status !== null && $job['status'] !== $status) {
                continue;
            }
            if ($queue !== null && $job['queue'] !== $queue) {
                continue;
            }
            $count++;
        }

        return $count;
    }

    public function pruneCompleted(int $days = 7): int
    {
        $threshold = gmdate(
            $this->dateFormat,
            $this->clock->timestamp() - ($days * 86400)
        );
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
        return array_map(fn($job) => JobData::fromRaw($job), $this->jobs);
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

    private function encodeJson(mixed $value, string $context): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new SerializationException(sprintf('Unable to encode %s as JSON', $context), 0, $exception);
        }
    }

    private function claimAvailableJob(int $id, string $workerId, string $now): ?ClaimedJob
    {
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

        return new ClaimedJob(JobData::fromRaw($this->jobs[$id]), $workerId, $leaseToken);
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
