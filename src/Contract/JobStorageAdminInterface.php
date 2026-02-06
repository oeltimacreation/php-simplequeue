<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Contract;

interface JobStorageAdminInterface
{
    /**
     * Get jobs by status.
     *
     * @param string|null $status Filter by status (null for all)
     * @param string|null $queue Filter by queue (null for all)
     * @param int $limit Maximum number of jobs to return
     * @param int $offset Offset for pagination
     * @return JobData[]
     */
    public function list(?string $status = null, ?string $queue = null, int $limit = 100, int $offset = 0): array;

    /**
     * Count jobs by status.
     *
     * @param string|null $status Filter by status (null for all)
     * @param string|null $queue Filter by queue (null for all)
     * @return int
     */
    public function count(?string $status = null, ?string $queue = null): int;

    /**
     * Delete completed jobs older than a given number of days.
     *
     * @param int $days Number of days to keep completed jobs
     * @return int Number of deleted jobs
     */
    public function pruneCompleted(int $days = 7): int;
}
