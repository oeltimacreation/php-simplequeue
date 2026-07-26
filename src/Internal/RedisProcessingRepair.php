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
     * @param string $processingKey Processing list key
     * @param string $processingZKey Processing timestamp sorted-set key
     * @param list<string> $ids Bounded processing-list slice
     */
    public static function repair(
        ClientInterface $redis,
        ClockInterface $clock,
        string $processingKey,
        string $processingZKey,
        array $ids
    ): void {
        $validIds = [];
        $invalidIds = [];
        foreach ($ids as $id) {
            if (RedisResponseNormalizer::isValidJobId($id)) {
                $validIds[] = $id;
            } else {
                $invalidIds[] = $id;
            }
        }

        $scores = self::scores($redis, $processingZKey, $validIds);
        $missingScores = count($scores) < count($validIds) || in_array(null, $scores, true);
        if ($invalidIds === [] && !$missingScores) {
            return;
        }

        /** @var \Predis\Pipeline\Pipeline $pipeline */
        $pipeline = $redis->pipeline();
        foreach ($invalidIds as $id) {
            $pipeline->lrem($processingKey, 0, $id);
            $pipeline->zrem($processingZKey, $id);
        }
        $timestamp = $missingScores ? $clock->timestamp() : 0;
        foreach ($validIds as $index => $id) {
            if (($scores[$index] ?? null) === null) {
                $pipeline->zadd($processingZKey, [(int) $id => $timestamp]);
            }
        }
        $pipeline->execute();
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
