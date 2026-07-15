<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Contract;

/** Provides stable, keyset-paginated access to pending jobs. */
interface SupportsPendingJobCursor
{
    /**
     * @return list<JobData> Pending jobs in ascending ID order
     */
    public function scanPending(string $queue, ?int $afterId, int $limit): array;
}
