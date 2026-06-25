<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Storage;

use Oeltima\SimpleQueue\Contract\ClaimedJob;
use Oeltima\SimpleQueue\Contract\ClockInterface;
use Oeltima\SimpleQueue\Contract\JobData;
use Oeltima\SimpleQueue\Contract\JobStatus;
use Oeltima\SimpleQueue\Contract\JobStorageAdminInterface;
use Oeltima\SimpleQueue\Contract\JobStorageInterface;
use Oeltima\SimpleQueue\SystemClock;

/**
 * In-memory job storage for testing purposes.
 *
 * This storage keeps all jobs in memory and is useful for unit testing.
 * All data is lost when the process terminates.
 */
class InMemoryJobStorage implements JobStorageInterface, JobStorageAdminInterface
{
    /** @var array<int, array<string, mixed>> */
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
            'status' => 'pending',
            'payload' => json_encode($payload),
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
                && in_array($job['status'], ['pending', 'running'], true)
            ) {
                return JobData::fromRaw($job);
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
        $now = $this->now();
        $candidateId = null;
        $candidateAvailableAt = null;

        foreach ($this->jobs as $id => $job) {
            if ($job['status'] !== 'pending') {
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
        $id = $claim->job->id;
        $this->jobs[$id]['status'] = 'completed';
        $this->jobs[$id]['result'] = $result === null ? null : json_encode($result);
        $this->jobs[$id]['completed_at'] = $now;
        $this->jobs[$id]['locked_by'] = null;
        $this->jobs[$id]['locked_at'] = null;
        $this->jobs[$id]['lease_token'] = null;
        $this->jobs[$id]['updated_at'] = $now;

        return true;
    }

    public function markFailed(ClaimedJob $claim, string $errorMessage, ?string $errorTrace = null): bool
    {
        if (!$this->ownsClaim($claim)) {
            return false;
        }

        $now = $this->now();
        $id = $claim->job->id;
        $this->jobs[$id]['status'] = 'failed';
        $this->jobs[$id]['error_message'] = $errorMessage;
        $this->jobs[$id]['error_trace'] = $errorTrace;
        $this->jobs[$id]['completed_at'] = $now;
        $this->jobs[$id]['locked_by'] = null;
        $this->jobs[$id]['locked_at'] = null;
        $this->jobs[$id]['lease_token'] = null;
        $this->jobs[$id]['updated_at'] = $now;

        return true;
    }

    public function updateProgress(ClaimedJob $claim, ?int $progress = null, ?string $message = null): bool
    {
        if (!$this->ownsClaim($claim)) {
            return false;
        }

        $id = $claim->job->id;
        $this->jobs[$id]['progress'] = $progress;
        $this->jobs[$id]['progress_message'] = $message;
        $this->jobs[$id]['locked_at'] = $this->now();
        $this->jobs[$id]['updated_at'] = $this->jobs[$id]['locked_at'];

        return true;
    }

    public function scheduleRetry(
        ClaimedJob $claim,
        int $attempts,
        int $delaySeconds,
        ?string $errorMessage = null
    ): bool {
        if (!$this->ownsClaim($claim)) {
            return false;
        }

        $now = $this->now();
        $availableAt = gmdate($this->dateFormat, (int) strtotime($now) + $delaySeconds);
        $id = $claim->job->id;

        $this->jobs[$id]['status'] = 'pending';
        $this->jobs[$id]['attempts'] = $attempts;
        $this->jobs[$id]['available_at'] = $availableAt;
        $this->jobs[$id]['error_message'] = $errorMessage;
        $this->jobs[$id]['locked_by'] = null;
        $this->jobs[$id]['locked_at'] = null;
        $this->jobs[$id]['lease_token'] = null;
        $this->jobs[$id]['updated_at'] = $now;

        return true;
    }

    public function heartbeat(ClaimedJob $claim): bool
    {
        if (!$this->ownsClaim($claim)) {
            return false;
        }

        $now = $this->now();
        $id = $claim->job->id;
        $this->jobs[$id]['locked_at'] = $now;
        $this->jobs[$id]['updated_at'] = $now;

        return true;
    }

    public function recoverStaleJobs(int $ttlSeconds): int
    {
        $now = $this->now();
        $staleThreshold = gmdate($this->dateFormat, (int) strtotime($now) - $ttlSeconds);
        $count = 0;

        foreach ($this->jobs as &$job) {
            if ($job['status'] !== 'running') {
                continue;
            }
            if ($job['locked_at'] === null || $job['locked_at'] >= $staleThreshold) {
                continue;
            }

            $attempts = isset($job['attempts']) && is_numeric($job['attempts']) ? (int) $job['attempts'] : 0;
            $maxAttempts = isset($job['max_attempts']) && is_numeric($job['max_attempts'])
                ? (int) $job['max_attempts']
                : 3;
            $nextAttempts = $attempts + 1;
            if ($nextAttempts >= $maxAttempts) {
                $job['status'] = 'failed';
                $job['error_message'] = 'Job timed out / worker crashed (stale recovery)';
                $job['completed_at'] = $now;
            } else {
                $job['status'] = 'pending';
                $job['attempts'] = $nextAttempts;
                $job['available_at'] = $now;
            }

            $job['locked_by'] = null;
            $job['locked_at'] = null;
            $job['lease_token'] = null;
            $job['updated_at'] = $now;
            $count++;
        }

        return $count;
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
            if ($status !== null && $job['status'] !== $status->value) {
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

    public function count(?JobStatus $status = null, ?string $queue = null): int
    {
        $count = 0;

        foreach ($this->jobs as $job) {
            if ($status !== null && $job['status'] !== $status->value) {
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
            if (!in_array($job['status'], ['completed', 'cancelled'], true)) {
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

        if ($this->jobs[$id]['status'] !== 'pending') {
            return false;
        }

        $now = $this->now();
        $this->jobs[$id]['status'] = 'cancelled';
        $this->jobs[$id]['updated_at'] = $now;

        return true;
    }

    private function now(): string
    {
        return $this->clock->now();
    }

    private function claimAvailableJob(int $id, string $workerId, string $now): ?ClaimedJob
    {
        if (!isset($this->jobs[$id])) {
            return null;
        }

        $job = $this->jobs[$id];
        $isPending = $job['status'] === 'pending' && $job['available_at'] <= $now;
        $isAlreadyLockedByMe = $job['status'] === 'running' && $job['locked_by'] === $workerId;

        if (!$isPending && !$isAlreadyLockedByMe) {
            return null;
        }

        $leaseToken = $this->generateLeaseToken();
        $this->jobs[$id]['status'] = 'running';
        $this->jobs[$id]['locked_by'] = $workerId;
        $this->jobs[$id]['locked_at'] = $now;
        $this->jobs[$id]['started_at'] = $now;
        $this->jobs[$id]['lease_token'] = $leaseToken;
        $this->jobs[$id]['updated_at'] = $now;

        return new ClaimedJob(JobData::fromRaw($this->jobs[$id]), $workerId, $leaseToken);
    }

    private function generateLeaseToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    private function ownsClaim(ClaimedJob $claim): bool
    {
        $id = $claim->job->id;

        return isset($this->jobs[$id])
            && $this->jobs[$id]['status'] === 'running'
            && $this->jobs[$id]['lease_token'] === $claim->leaseToken;
    }
}
