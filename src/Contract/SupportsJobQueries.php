<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Contract;

/** Optional compact monitoring queries with bounded input and deterministic ordering. */
interface SupportsJobQueries
{
    /**
     * @param JobStatus|null $status Optional status
     * @param string|null $queue Optional queue
     * @param int|null $beforeId Exclusive descending ID cursor
     * @param int $limit Maximum summaries, 1–1000
     * @return list<JobSummary> Descending IDs
     */
    public function listSummaries(
        ?JobStatus $status = null,
        ?string $queue = null,
        ?int $beforeId = null,
        int $limit = 100
    ): array;

    /**
     * @param list<int> $jobIds At most 1000 positive IDs; duplicates collapse
     * @return array<int, JobSummary> ID-keyed summaries in first-requested order; missing IDs omitted
     */
    public function findSummaries(array $jobIds): array;

    /**
     * @param string|null $queue Optional queue filter
     * @return array<string, int> Every JobStatus value, including zero counts
     */
    public function countByStatus(?string $queue = null): array;
}
