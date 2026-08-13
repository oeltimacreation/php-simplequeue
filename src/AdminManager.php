<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue;

use Oeltima\SimpleQueue\Contract\FailedJobAdminInterface;
use Oeltima\SimpleQueue\Contract\JobData;
use Oeltima\SimpleQueue\Contract\JobStatus;
use Oeltima\SimpleQueue\Contract\JobStorageAdminInterface;
use Oeltima\SimpleQueue\Contract\JobStorageInterface;
use Oeltima\SimpleQueue\Contract\SupportsFailedJobAdministration;
use Oeltima\SimpleQueue\Contract\SupportsJobRemoval;
use Oeltima\SimpleQueue\Exception\QueueException;
use Oeltima\SimpleQueue\Internal\PositiveJobId;

/**
 * Coordinates failed-job administration across durable storage and queue notifications.
 */
final class AdminManager implements FailedJobAdminInterface
{
    /**
     * @param JobStorageInterface&JobStorageAdminInterface&SupportsFailedJobAdministration $storage
     *        Storage with failed-job administration capabilities
     * @param QueueManager $queueManager Queue notification manager
     */
    public function __construct(
        private readonly JobStorageInterface&JobStorageAdminInterface&SupportsFailedJobAdministration $storage,
        private readonly QueueManager $queueManager
    ) {
    }

    /**
     * List failed jobs, optionally restricted to a queue.
     *
     * @param string|null $queue Queue name, or null for all queues
     * @param int $limit Maximum number of jobs to return
     * @param int $offset Offset for pagination
     * @return list<JobData> Failed jobs
     */
    public function listFailed(?string $queue = null, int $limit = 100, int $offset = 0): array
    {
        $this->validatePagination($limit, $offset);

        return array_values($this->storage->list(JobStatus::Failed, $queue, $limit, $offset));
    }

    /**
     * Inspect one failed job.
     *
     * @param int $jobId Job identifier
     * @return JobData|null Failed job, or null when it is missing or no longer failed
     */
    public function inspectFailed(int $jobId): ?JobData
    {
        $job = $this->storage->find(PositiveJobId::fromInt($jobId)->value);

        return $job?->status === JobStatus::Failed ? $job : null;
    }

    /**
     * Reset a failed job for a fresh execution attempt and notify the queue.
     *
     * @param int $jobId Job identifier
     * @return bool True when the failed job was re-queued
     */
    public function requeueFailed(int $jobId): bool
    {
        $job = $this->storage->requeueFailed(PositiveJobId::fromInt($jobId)->value);
        if ($job === null) {
            return false;
        }

        try {
            $this->queueManager->enqueue($job->id, $job->queue);
        } catch (\Throwable $exception) {
            throw new QueueException(
                'Failed job was re-queued but queue notification failed',
                0,
                $exception
            );
        }

        return true;
    }

    /**
     * Permanently remove a failed job and its queue notifications.
     *
     * @param int $jobId Job identifier
     * @return bool True when the failed job was purged
     */
    public function purgeFailed(int $jobId): bool
    {
        $normalizedId = PositiveJobId::fromInt($jobId)->value;
        $job = $this->inspectFailed($normalizedId);
        if ($job === null) {
            return false;
        }

        $driver = $this->removalDriver();
        $purged = $this->storage->purgeFailed($normalizedId);
        if ($purged === null) {
            return false;
        }

        try {
            $driver->remove($job->queue, $normalizedId);
        } catch (\Throwable $exception) {
            throw new QueueException(
                'Failed job was purged but queue notification cleanup failed',
                0,
                $exception
            );
        }

        return true;
    }

    private function validatePagination(int $limit, int $offset): void
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('Failed job limit must be positive');
        }
        if ($offset < 0) {
            throw new \InvalidArgumentException('Failed job offset must not be negative');
        }
    }

    private function removalDriver(): SupportsJobRemoval
    {
        $driver = $this->queueManager->driver();
        if (!$driver instanceof SupportsJobRemoval) {
            throw new QueueException('Failed job purge requires queue notification removal support');
        }

        return $driver;
    }
}
