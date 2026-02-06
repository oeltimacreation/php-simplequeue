<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue;

use Oeltima\SimpleQueue\Contract\JobData;
use Oeltima\SimpleQueue\Contract\JobStorageInterface;

/**
 * Service for dispatching jobs to the queue.
 *
 * Provides a simple API for creating jobs and adding them to the queue.
 */
final class JobDispatcher
{
    private JobStorageInterface $storage;
    private QueueManager $queueManager;

    public function __construct(JobStorageInterface $storage, QueueManager $queueManager)
    {
        $this->storage = $storage;
        $this->queueManager = $queueManager;
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
        $jobIds = [];
        foreach ($payloads as $payload) {
            $jobIds[] = $this->dispatch($type, $payload, $queue, $maxAttempts);
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
}
