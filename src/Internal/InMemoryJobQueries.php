<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Internal;

use Oeltima\SimpleQueue\Contract\JobSummary;
use Oeltima\SimpleQueue\Contract\JobStatus;

/** @internal @mixin \Oeltima\SimpleQueue\Storage\InMemoryJobStorage */
trait InMemoryJobQueries
{
    /** @inheritDoc */
    public function listSummaries(
        ?JobStatus $status = null,
        ?string $queue = null,
        ?int $beforeId = null,
        int $limit = 100
    ): array {
        JobQueryRules::validate($queue, $beforeId, $limit);
        $filter = new JobFilter($status, $queue);
        $ids = array_keys($this->jobs);
        rsort($ids, SORT_NUMERIC);
        $summaries = [];
        foreach ($ids as $id) {
            $row = $this->jobs[$id];
            if ($beforeId !== null && $id >= $beforeId) {
                continue;
            }
            if (!$filter->matches($row['status'], $row['queue'])) {
                continue;
            }
            $summaries[] = JobSummary::fromRaw($row);
            if (count($summaries) === $limit) {
                break;
            }
        }
        return $summaries;
    }

    /** @inheritDoc */
    public function findSummaries(array $jobIds): array
    {
        $summaries = [];
        foreach (JobQueryRules::identities($jobIds) as $id) {
            if (isset($this->jobs[$id])) {
                $summaries[$id] = JobSummary::fromRaw($this->jobs[$id]);
            }
        }
        return $summaries;
    }

    /** @inheritDoc */
    public function countByStatus(?string $queue = null): array
    {
        JobQueryRules::validate($queue, null, 1);
        $counts = JobQueryRules::emptyCounts();
        foreach ($this->jobs as $row) {
            if ($queue === null || $row['queue'] === $queue) {
                $counts[$row['status']->value]++;
            }
        }
        return $counts;
    }
}
