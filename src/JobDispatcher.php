<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue;

use Oeltima\SimpleQueue\Contract\JobData;
use Oeltima\SimpleQueue\Contract\JobStorageInterface;
use Oeltima\SimpleQueue\Contract\SupportsBatchEnqueue;
use Oeltima\SimpleQueue\Contract\SupportsIdempotentJobCreation;
use Oeltima\SimpleQueue\Contract\SupportsJobRemoval;
use Oeltima\SimpleQueue\Exception\QueueException;

/**
 * Service for dispatching jobs to the queue.
 *
 * Provides a simple API for creating jobs and adding them to the queue.
 */
final class JobDispatcher
{
    public function __construct(
        private readonly JobStorageInterface $storage,
        private readonly QueueManager $queueManager
    ) {
    }

    /**
     * Dispatch a job to the background queue.
     *
     * @param string $type Job type identifier (must be registered in JobRegistry)
     * @param array<string, mixed> $payload Job payload data
     * @param string $queue Queue name (default: 'default')
     * @param int $maxAttempts Maximum retry attempts (default: 3)
     * @param string|null $requestId Optional request correlation ID
     * @return int The created job ID
     */
    public function dispatch(
        string $type,
        array $payload,
        string $queue = 'default',
        int $maxAttempts = 3,
        ?string $requestId = null
    ): int {
        $this->validateDispatchArguments($type, $queue, $maxAttempts, $requestId);
        $jobId = $this->storage->createJob($type, $payload, $queue, $maxAttempts, $requestId);
        $this->queueManager->enqueue($jobId, $queue);

        return $jobId;
    }

    /**
     * Dispatch a job idempotently, returning existing job if one with the same requestId is active.
     *
     * @param string $type Job type identifier
     * @param array<string, mixed> $payload Job payload data
     * @param string $requestId Request correlation ID (required for idempotency)
     * @param string $queue Queue name (default: 'default')
     * @param int $maxAttempts Maximum retry attempts (default: 3)
     * @return array{job_id: int, created: bool}
     */
    public function dispatchIdempotent(
        string $type,
        array $payload,
        string $requestId,
        string $queue = 'default',
        int $maxAttempts = 3
    ): array {
        $this->validateDispatchArguments($type, $queue, $maxAttempts, $requestId);
        if ($requestId === '') {
            throw new \InvalidArgumentException('Request ID must not be empty for idempotent dispatch');
        }
        if ($this->storage instanceof SupportsIdempotentJobCreation) {
            $result = $this->storage->createIdempotentJob($type, $payload, $requestId, $queue, $maxAttempts);
            if ($result->created) {
                $this->queueManager->enqueue($result->jobId, $queue);
            }
            return ['job_id' => $result->jobId, 'created' => $result->created];
        }
        $existing = $this->storage->findActiveByRequestId($requestId);

        if ($existing !== null) {
            return ['job_id' => $existing->id, 'created' => false];
        }

        $jobId = $this->dispatch($type, $payload, $queue, $maxAttempts, $requestId);

        return ['job_id' => $jobId, 'created' => true];
    }

    /**
     * Dispatch multiple jobs of the same type.
     *
     * @param string $type Job type identifier
     * @param array<array<string, mixed>> $payloads Array of job payloads
     * @param string $queue Queue name
     * @param int $maxAttempts Maximum retry attempts
     * @return int[] Array of created job IDs
     */
    public function dispatchBatch(
        string $type,
        array $payloads,
        string $queue = 'default',
        int $maxAttempts = 3
    ): array {
        $this->validateDispatchArguments($type, $queue, $maxAttempts, null);
        $jobs = [];
        foreach ($payloads as $payload) {
            $jobs[] = [
                'type' => $type,
                'payload' => $payload,
                'queue' => $queue,
                'maxAttempts' => $maxAttempts,
            ];
        }

        $jobIds = $this->storage->createJobs($jobs);

        $driver = $this->queueManager->driver();
        if ($driver instanceof SupportsBatchEnqueue) {
            $driver->enqueueBatch($queue, $jobIds);
        } else {
            foreach ($jobIds as $jobId) {
                $this->queueManager->enqueue($jobId, $queue);
            }
        }

        return $jobIds;
    }

    /**
     * Get the status of a job.
     *
     * @param int $jobId Job identifier
     * @return JobData|null Job data or null if not found
     */
    public function getStatus(int $jobId): ?JobData
    {
        return $this->storage->find($jobId);
    }

    /**
     * Get the underlying queue manager.
     */
    public function getQueueManager(): QueueManager
    {
        return $this->queueManager;
    }

    /**
     * Get the underlying job storage.
     */
    public function getStorage(): JobStorageInterface
    {
        return $this->storage;
    }

    /**
     * Cancel a pending job.
     *
     * @param int $jobId Job identifier
     * @return bool True if the job was successfully cancelled
     */
    public function cancelJob(int $jobId): bool
    {
        if ($jobId < 1) {
            throw new \InvalidArgumentException('Job ID must be a positive integer');
        }
        $job = $this->storage->find($jobId);
        $cancelled = $this->storage->cancel($jobId);
        if (($cancelled || $job?->status->value === 'cancelled') && $job !== null) {
            $driver = $this->queueManager->driver();
            if ($driver instanceof SupportsJobRemoval) {
                try {
                    $driver->remove($job->queue, $jobId);
                } catch (\Throwable $exception) {
                    throw new QueueException('Job was cancelled but queue notification cleanup failed', 0, $exception);
                }
            }
        }
        return $cancelled;
    }

    private function validateDispatchArguments(string $type, string $queue, int $maxAttempts, ?string $requestId): void
    {
        if (trim($type) === '' || trim($queue) === '') {
            throw new \InvalidArgumentException('Job type and queue must not be empty');
        }
        if ($maxAttempts < 1) {
            throw new \InvalidArgumentException('Maximum attempts must be at least 1');
        }
        if ($requestId !== null && trim($requestId) === '') {
            throw new \InvalidArgumentException('Request ID must not be empty when provided');
        }
    }
}
