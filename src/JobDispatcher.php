<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue;

use Oeltima\SimpleQueue\Contract\ClockInterface;
use Oeltima\SimpleQueue\Contract\JobData;
use Oeltima\SimpleQueue\Contract\JobStatus;
use Oeltima\SimpleQueue\Contract\JobStorageInterface;
use Oeltima\SimpleQueue\Contract\SupportsBatchEnqueue;
use Oeltima\SimpleQueue\Contract\SupportsIdempotentJobCreation;
use Oeltima\SimpleQueue\Contract\SupportsJobRemoval;
use Oeltima\SimpleQueue\Exception\QueueException;
use Oeltima\SimpleQueue\Internal\PositiveJobId;

/**
 * Service for dispatching jobs to the queue.
 *
 * Provides a simple API for creating jobs and adding them to the queue.
 */
final class JobDispatcher
{
    private readonly ClockInterface $clock;

    public function __construct(
        private readonly JobStorageInterface $storage,
        private readonly QueueManager $queueManager,
        ?ClockInterface $clock = null
    ) {
        $this->clock = $clock ?? new SystemClock();
    }

    /**
     * Dispatch a job to the background queue.
     *
     * @param string $type Job type identifier (must be registered in JobRegistry)
     * @param array<string, mixed> $payload Job payload data
     * @param string $queue Queue name (default: 'default')
     * @param int $maxAttempts Maximum retry attempts (default: 3)
     * @param string|null $requestId Optional request correlation ID
     * @param int|\DateTimeInterface|null $availableAt Optional first-availability timestamp
     * @return int The created job ID
     */
    public function dispatch(
        string $type,
        array $payload,
        string $queue = 'default',
        int $maxAttempts = 3,
        ?string $requestId = null,
        int|\DateTimeInterface|null $availableAt = null
    ): int {
        $this->validateDispatchArguments($type, $queue, $maxAttempts, $requestId);
        $resolvedAt = $this->resolveAvailableAt($availableAt);

        if ($resolvedAt === null) {
            $jobId = $this->storage->createJob($type, $payload, $queue, $maxAttempts, $requestId);
            $this->queueManager->enqueue($jobId, $queue);

            return $jobId;
        }

        $jobIds = $this->storage->createJobs([[
            'type' => $type,
            'payload' => $payload,
            'queue' => $queue,
            'maxAttempts' => $maxAttempts,
            'requestId' => $requestId,
            'availableAt' => $resolvedAt,
        ]]);
        $this->queueManager->enqueueDelayed($jobIds[0], $queue, $resolvedAt);

        return $jobIds[0];
    }

    /**
     * Schedule a job at an absolute timestamp.
     *
     * Values in the past or equal to now are dispatched immediately.
     *
     * @param int|\DateTimeInterface $timestamp Unix timestamp or date/time object
     * @param string $type Job type identifier
     * @param array<string, mixed> $payload Job payload data
     * @param string $queue Queue name (default: 'default')
     * @param int $maxAttempts Maximum retry attempts (default: 3)
     * @param string|null $requestId Optional request correlation ID
     * @return int The created job ID
     */
    public function dispatchAt(
        int|\DateTimeInterface $timestamp,
        string $type,
        array $payload,
        string $queue = 'default',
        int $maxAttempts = 3,
        ?string $requestId = null
    ): int {
        return $this->dispatch($type, $payload, $queue, $maxAttempts, $requestId, $timestamp);
    }

    /**
     * Schedule a job after a relative delay.
     *
     * A zero delay dispatches immediately.
     *
     * @param int $delaySeconds Seconds to wait before the job is available
     * @param string $type Job type identifier
     * @param array<string, mixed> $payload Job payload data
     * @param string $queue Queue name (default: 'default')
     * @param int $maxAttempts Maximum retry attempts (default: 3)
     * @param string|null $requestId Optional request correlation ID
     * @return int The created job ID
     */
    public function dispatchAfter(
        int $delaySeconds,
        string $type,
        array $payload,
        string $queue = 'default',
        int $maxAttempts = 3,
        ?string $requestId = null
    ): int {
        if ($delaySeconds < 0) {
            throw new \InvalidArgumentException('Dispatch delay must not be negative');
        }

        return $this->dispatchAt(
            $this->clock->timestamp() + $delaySeconds,
            $type,
            $payload,
            $queue,
            $maxAttempts,
            $requestId
        );
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
     * @param int|\DateTimeInterface|null $availableAt Optional first-availability timestamp
     * @return int[] Array of created job IDs
     */
    public function dispatchBatch(
        string $type,
        array $payloads,
        string $queue = 'default',
        int $maxAttempts = 3,
        int|\DateTimeInterface|null $availableAt = null
    ): array {
        $this->validateDispatchArguments($type, $queue, $maxAttempts, null);
        $resolvedAt = $this->resolveAvailableAt($availableAt);

        $jobIds = $this->storage->createJobs(
            $this->batchDefinitions($type, $payloads, $queue, $maxAttempts, $resolvedAt)
        );

        if ($resolvedAt === null) {
            $this->notifyBatch($queue, $jobIds);
        } else {
            $this->queueManager->enqueueDelayedBatch($jobIds, $queue, $resolvedAt);
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
        $jobId = PositiveJobId::fromInt($jobId)->value;
        $job = $this->storage->find($jobId);
        $cancelled = $this->storage->cancel($jobId);
        if (($cancelled || $job?->status === JobStatus::Cancelled) && $job !== null) {
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

    /**
     * Resolve and clamp a dispatch availability timestamp.
     *
     * Past and present values follow the immediate dispatch path. Non-positive
     * timestamps are rejected.
     *
     * @param int|\DateTimeInterface|null $availableAt Raw availability value
     * @return int|null Unix timestamp when the job becomes available, or null for immediate dispatch
     */
    private function resolveAvailableAt(int|\DateTimeInterface|null $availableAt): ?int
    {
        if ($availableAt === null) {
            return null;
        }
        $timestamp = $availableAt instanceof \DateTimeInterface
            ? $availableAt->getTimestamp()
            : $availableAt;
        if ($timestamp <= 0) {
            throw new \InvalidArgumentException('Available-at timestamp must be a positive Unix timestamp');
        }

        return $timestamp <= $this->clock->timestamp() ? null : $timestamp;
    }

    /**
     * Build createJobs() definitions for a batch dispatch.
     *
     * @param string $type Job type identifier
     * @param array<array<string, mixed>> $payloads Array of job payloads
     * @param string $queue Queue name
     * @param int $maxAttempts Maximum retry attempts
     * @param int|null $resolvedAt Resolved availability timestamp or null for immediate dispatch
     * @return array<int, array<string, mixed>> Job definitions
     */
    private function batchDefinitions(
        string $type,
        array $payloads,
        string $queue,
        int $maxAttempts,
        ?int $resolvedAt
    ): array {
        $definitions = [];
        foreach ($payloads as $payload) {
            $definition = [
                'type' => $type,
                'payload' => $payload,
                'queue' => $queue,
                'maxAttempts' => $maxAttempts,
            ];
            if ($resolvedAt !== null) {
                $definition['availableAt'] = $resolvedAt;
            }
            $definitions[] = $definition;
        }

        return $definitions;
    }

    /**
     * Notify the queue driver for an immediate batch dispatch.
     *
     * @param string $queue Queue name
     * @param int[] $jobIds Created job IDs
     */
    private function notifyBatch(string $queue, array $jobIds): void
    {
        $driver = $this->queueManager->driver();
        if ($driver instanceof SupportsBatchEnqueue) {
            $driver->enqueueBatch($queue, $jobIds);
            return;
        }
        foreach ($jobIds as $jobId) {
            $this->queueManager->enqueue($jobId, $queue);
        }
    }
}
