<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Internal;

use Oeltima\SimpleQueue\Contract\ClockInterface;
use Predis\ClientInterface;

/**
 * Repairs blocking-dequeue notifications that are missing visibility scores.
 *
 * @internal
 */
final class RedisProcessingRepair
{
    /**
     * Repair one bounded processing-list slice using pipelined score checks and writes.
     *
     * @param ClientInterface $redis Predis client
     * @param ClockInterface $clock Visibility timestamp source
     * @param array{processing: string, scores: string} $keys Processing list and score keys
     * @param list<string> $ids Bounded processing-list slice
     */
    public static function repair(
        ClientInterface $redis,
        ClockInterface $clock,
        array $keys,
        array $ids
    ): void {
        [$validIds, $invalidIds] = self::partitionIds($ids);
        $scores = self::scores($redis, $keys['scores'], $validIds);
        $missingScores = count($scores) < count($validIds) || in_array(null, $scores, true);
        if ($invalidIds === [] && !$missingScores) {
            return;
        }

        /** @var \Predis\Pipeline\Pipeline $pipeline */
        $pipeline = $redis->pipeline();
        self::removeInvalid($pipeline, $keys, $invalidIds);
        $timestamp = $missingScores ? $clock->timestamp() : 0;
        self::addMissingScores($pipeline, $keys['scores'], [
            'ids' => $validIds,
            'scores' => $scores,
            'timestamp' => $timestamp,
        ]);
        $pipeline->execute();
    }

    /**
     * @param list<string> $ids
     * @return array{list<string>, list<string>}
     */
    private static function partitionIds(array $ids): array
    {
        $valid = [];
        $invalid = [];
        foreach ($ids as $id) {
            if (RedisResponseNormalizer::isValidJobId($id)) {
                $valid[] = $id;
                continue;
            }
            $invalid[] = $id;
        }
        return [$valid, $invalid];
    }

    /**
     * @param \Predis\Pipeline\Pipeline $pipeline
     * @param array{processing: string, scores: string} $keys
     * @param list<string> $invalidIds
     */
    private static function removeInvalid(object $pipeline, array $keys, array $invalidIds): void
    {
        foreach ($invalidIds as $id) {
            $pipeline->lrem($keys['processing'], 0, $id);
            $pipeline->zrem($keys['scores'], $id);
        }
    }

    /**
     * @param \Predis\Pipeline\Pipeline $pipeline
     * @param array{ids: list<string>, scores: list<mixed>, timestamp: int} $repair
     */
    private static function addMissingScores(
        object $pipeline,
        string $scoresKey,
        array $repair
    ): void {
        foreach ($repair['ids'] as $index => $id) {
            if (($repair['scores'][$index] ?? null) === null) {
                $pipeline->zadd($scoresKey, [(int) $id => $repair['timestamp']]);
            }
        }
    }

    /**
     * @param ClientInterface $redis Predis client
     * @param string $processingZKey Processing timestamp sorted-set key
     * @param list<string> $ids Valid job IDs
     * @return list<mixed> Score responses in request order
     */
    private static function scores(ClientInterface $redis, string $processingZKey, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        /** @var \Predis\Pipeline\Pipeline $pipeline */
        $pipeline = $redis->pipeline();
        foreach ($ids as $id) {
            $pipeline->zscore($processingZKey, $id);
        }
        return array_values($pipeline->execute());
    }
}
