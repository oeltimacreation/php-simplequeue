<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Storage;

use Oeltima\SimpleQueue\Contract\JobData;
use Oeltima\SimpleQueue\Contract\JobStorageInterface;

/**
 * In-memory job storage for testing purposes.
 *
 * This storage keeps all jobs in memory and is useful for unit testing.
 * All data is lost when the process terminates.
 */
class InMemoryJobStorage implements JobStorageInterface
{
    /** @var array<int, array<string, mixed>> */
    private array $jobs = [];

    private int $nextId = 1;
    private string $dateFormat = 'Y-m-d H:i:s';

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
            'available_at' => null,
            'started_at' => null,
            'completed_at' => null,
            'locked_by' => null,
            'locked_at' => null,
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

    public function find(int $id): ?JobData
    {
        if (!isset($this->jobs[$id])) {
            return null;
        }

        return JobData::fromRaw($this->jobs[$id]);
    }

    public function getNextPendingJobId(string $queue = 'default'): ?int
    {
        $now = $this->now();

        foreach ($this->jobs as $id => $job) {
            if ($job['status'] !== 'pending') {
                continue;
            }
            if ($job['queue'] !== $queue) {
                continue;
            }
            if ($job['available_at'] !== null && $job['available_at'] > $now) {
                continue;
            }
            return $id;
        }

        return null;
    }

    public function claimJob(int $id, string $workerId): bool
    {
        if (!isset($this->jobs[$id])) {
            return false;
        }

        if ($this->jobs[$id]['status'] !== 'pending') {
            return false;
        }

        $now = $this->now();
        $this->jobs[$id]['status'] = 'running';
        $this->jobs[$id]['locked_by'] = $workerId;
        $this->jobs[$id]['locked_at'] = $now;
        $this->jobs[$id]['started_at'] = $now;
        $this->jobs[$id]['updated_at'] = $now;

        return true;
    }

    public function markCompleted(int $id, mixed $result = null): bool
    {
        if (!isset($this->jobs[$id])) {
            return false;
        }

        $now = $this->now();
        $this->jobs[$id]['status'] = 'completed';
        $this->jobs[$id]['result'] = $result === null ? null : json_encode($result);
        $this->jobs[$id]['completed_at'] = $now;
        $this->jobs[$id]['updated_at'] = $now;

        return true;
    }

    public function markFailed(int $id, string $errorMessage, ?string $errorTrace = null): bool
    {
        if (!isset($this->jobs[$id])) {
            return false;
        }

        $now = $this->now();
        $this->jobs[$id]['status'] = 'failed';
        $this->jobs[$id]['error_message'] = $errorMessage;
        $this->jobs[$id]['error_trace'] = $errorTrace;
        $this->jobs[$id]['completed_at'] = $now;
        $this->jobs[$id]['updated_at'] = $now;

        return true;
    }

    public function updateProgress(int $id, ?int $progress = null, ?string $message = null): bool
    {
        if (!isset($this->jobs[$id])) {
            return false;
        }

        $this->jobs[$id]['progress'] = $progress;
        $this->jobs[$id]['progress_message'] = $message;
        $this->jobs[$id]['updated_at'] = $this->now();

        return true;
    }

    public function scheduleRetry(int $id, int $attempts, int $delaySeconds, ?string $errorMessage = null): bool
    {
        if (!isset($this->jobs[$id])) {
            return false;
        }

        $now = $this->now();
        $availableAt = date($this->dateFormat, strtotime($now) + $delaySeconds);

        $this->jobs[$id]['status'] = 'pending';
        $this->jobs[$id]['attempts'] = $attempts;
        $this->jobs[$id]['available_at'] = $availableAt;
        $this->jobs[$id]['error_message'] = $errorMessage;
        $this->jobs[$id]['locked_by'] = null;
        $this->jobs[$id]['locked_at'] = null;
        $this->jobs[$id]['updated_at'] = $now;

        return true;
    }

    public function recoverStaleJobs(int $ttlSeconds): int
    {
        $now = $this->now();
        $staleThreshold = date($this->dateFormat, strtotime($now) - $ttlSeconds);
        $count = 0;

        foreach ($this->jobs as &$job) {
            if ($job['status'] !== 'running') {
                continue;
            }
            if ($job['locked_at'] === null || $job['locked_at'] >= $staleThreshold) {
                continue;
            }

            $job['status'] = 'pending';
            $job['locked_by'] = null;
            $job['locked_at'] = null;
            $job['available_at'] = null;
            $job['updated_at'] = $now;
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

    private function now(): string
    {
        return date($this->dateFormat);
    }
}
