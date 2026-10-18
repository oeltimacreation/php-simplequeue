<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Internal;

/**
 * @internal
 * @mixin \Oeltima\SimpleQueue\Storage\InMemoryJobStorage
 * @phpstan-import-type JobDefinitionShape from \Oeltima\SimpleQueue\Contract\JobStorageInterface
 */
trait InMemoryIdempotentBatches
{
    /**
     * @param array<int, JobDefinitionShape> $jobs Definitions with request IDs
     * @return list<\Oeltima\SimpleQueue\Contract\IdempotentJobResult> Input-ordered atomic outcomes
     */
    public function createIdempotentJobs(array $jobs): array
    {
        $jobs = \Oeltima\SimpleQueue\Internal\IdempotentBatch::validate($jobs, $this->clock);
        $ids = [];
        foreach ($this->jobs as $row) {
            if ($row['request_id'] !== null && !$row['status']->isTerminal()) {
                $ids['request:' . $row['request_id']] = $row['id'];
            }
        }
        $missing = [];
        foreach ($jobs as $job) {
            $key = \Oeltima\SimpleQueue\Internal\IdempotentBatch::key($job);
            if (!isset($ids[$key])) {
                $missing[$key] ??= $job;
            }
        }
        $newIds = $this->createJobs(array_values($missing));
        foreach (array_keys($missing) as $index => $key) {
            $ids[$key] = $newIds[$index];
        }
        return \Oeltima\SimpleQueue\Internal\IdempotentBatch::outcomes(
            $jobs,
            $ids,
            array_fill_keys($newIds, true)
        );
    }
}
