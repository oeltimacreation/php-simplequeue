<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Internal;

use Oeltima\SimpleQueue\Contract\ClockInterface;
use Oeltima\SimpleQueue\Contract\IdempotentJobResult;

/**
 * @internal
 * @phpstan-import-type JobDefinitionShape from \Oeltima\SimpleQueue\Contract\JobStorageInterface
 */
final class IdempotentBatch
{
    /**
     * @param array<int, JobDefinitionShape> $jobs Definitions
     * @param ClockInterface $clock Availability clock
     * @return list<JobDefinitionShape> Fully validated definitions
     */
    public static function validate(array $jobs, ClockInterface $clock): array
    {
        foreach ($jobs as $job) {
            JobStorageRules::validateJobDefinition($job, $clock);
            $requestId = $job['requestId'] ?? null;
            if (!is_string($requestId) || trim($requestId) === '') {
                throw new \InvalidArgumentException('Every idempotent batch definition requires a request ID');
            }
        }
        return array_values($jobs);
    }

    /**
     * @param list<JobDefinitionShape> $jobs Validated input
     * @param array<string, int> $ids Request-key to ID
     * @param array<int, true> $created Created identities
     * @return list<IdempotentJobResult> Input-ordered outcomes
     */
    public static function outcomes(array $jobs, array $ids, array $created): array
    {
        $seen = [];
        $outcomes = [];
        foreach ($jobs as $job) {
            $key = self::key($job);
            $id = $ids[$key];
            $outcomes[] = new IdempotentJobResult($id, isset($created[$id]) && !isset($seen[$id]));
            $seen[$id] = true;
        }
        return $outcomes;
    }

    /** @param JobDefinitionShape $job Validated definition @return string Stable PHP map key */
    public static function key(array $job): string
    {
        return 'request:' . ($job['requestId'] ?? '');
    }
}
