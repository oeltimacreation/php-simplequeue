<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Internal;

use Predis\ClientInterface;

/**
 * Repairs blocking-dequeue notifications that are missing visibility scores.
 *
 * @internal
 */
final readonly class RedisProcessingRepair
{
    private const REPAIR = <<<'LUA'
local ids = redis.call('LRANGE', KEYS[1], ARGV[1], ARGV[2])
local timestamp = ARGV[3]
local maximum = ARGV[4]
local seen = {}
for _, id in ipairs(ids) do
    if not seen[id] then
        seen[id] = true
        local fits = #id < #maximum or (#id == #maximum and id <= maximum)
        if fits and string.match(id, '^[1-9][0-9]*$') then
            redis.call('ZADD', KEYS[2], 'NX', timestamp, id)
        else
            redis.call('LREM', KEYS[1], 0, id)
            redis.call('ZREM', KEYS[2], id)
        end
    end
end
return #ids
LUA;

    private const RECOVER_STALE_LUA = <<<'LUA'
local processingZKey = KEYS[1]
local processingKey = KEYS[2]
local pendingKey = KEYS[3]
local staleThreshold = tonumber(ARGV[1])
local limit = tonumber(ARGV[2])
local now = tonumber(ARGV[3])

local staleJobs = redis.call('ZRANGEBYSCORE', processingZKey, '-inf', staleThreshold, 'LIMIT', 0, limit)
local recovered = 0
local chunkSize = 1000
for i = 1, #staleJobs, chunkSize do
    local j = math.min(i + chunkSize - 1, #staleJobs)
    local pending = {}
    for k = i, j do
        local id = staleJobs[k]
        local removed = redis.call('LREM', processingKey, 1, id)
        if removed > 0 then
            pending[#pending + 1] = id
        end
        if removed > 0 and redis.call('LPOS', processingKey, id) then
            redis.call('ZADD', processingZKey, now, id)
        else
            redis.call('ZREM', processingZKey, id)
        end
    end
    if #pending > 0 then
        redis.call('LPUSH', pendingKey, unpack(pending))
        recovered = recovered + #pending
    end
end
return recovered
LUA;

    /**
     * @param ClientInterface $redis Predis client
     * @param RedisScriptRunner $scripts Cached script runner
     */
    public function __construct(private ClientInterface $redis, private RedisScriptRunner $scripts)
    {
    }

    /**
     * Read and repair one processing-list slice atomically.
     *
     * @param array{processing: string, scores: string} $keys Processing list and score keys
     * @param int $cursor Starting index, inclusive
     * @param int $limit Maximum size of the slice
     * @param int $timestamp Score for newly visible notifications
     * @return int Number of list entries inspected
     */
    public function repair(array $keys, int $cursor, int $limit, int $timestamp): int
    {
        $count = $this->redis->eval(
            self::REPAIR,
            2,
            $keys['processing'],
            $keys['scores'],
            (string) $cursor,
            (string) ($cursor + $limit - 1),
            (string) $timestamp,
            (string) PHP_INT_MAX
        );
        return RedisResponseNormalizer::integer($count);
    }

    /**
     * Restore stale processing notifications and discard scores without a list entry.
     *
     * @param array{string, string, string} $keys Score, processing and pending keys
     * @param int $now Current timestamp
     * @param int $ttlSeconds Maximum processing age
     * @param int $limit Maximum scores to inspect
     * @return int Number of notifications restored
     */
    public function recoverStale(array $keys, int $now, int $ttlSeconds, int $limit): int
    {
        $result = $this->scripts->run(
            self::RECOVER_STALE_LUA,
            $keys,
            [(string) ($now - $ttlSeconds), (string) $limit, (string) $now]
        );
        return RedisResponseNormalizer::integer($result);
    }
}
