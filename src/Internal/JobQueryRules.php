<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Internal;

use Oeltima\SimpleQueue\Contract\JobStatus;

/** @internal Shared monitoring query boundaries. */
final class JobQueryRules
{
    public static function validate(?string $queue, ?int $beforeId, int $limit): void
    {
        if ($queue !== null) {
            JobStorageRules::validateQueueOrType($queue, 'Queue');
        }
        if ($beforeId !== null) {
            JobStorageRules::validatePositiveId($beforeId);
        }
        if ($limit < 1 || $limit > 1000) {
            throw new \InvalidArgumentException('Summary limit must be between 1 and 1000');
        }
    }

    /**
     * @param list<int> $ids Input IDs
     * @return list<int> Validated unique IDs
     */
    public static function identities(array $ids): array
    {
        if (count($ids) > 1000) {
            throw new \InvalidArgumentException('At most 1000 summary IDs are allowed');
        }
        foreach ($ids as $id) {
            JobStorageRules::validatePositiveId($id);
        }
        return array_values(array_unique($ids));
    }

    /** @return array<string, int> Empty counts for every durable status */
    public static function emptyCounts(): array
    {
        $names = array_map(static fn (JobStatus $status): string => $status->value, JobStatus::cases());
        return array_fill_keys($names, 0);
    }
}
