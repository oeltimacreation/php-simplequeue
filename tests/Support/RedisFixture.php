<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Support;

use Oeltima\SimpleQueue\Contract\ClockInterface;
use Oeltima\SimpleQueue\Driver\RedisQueueDriver;
use Oeltima\SimpleQueue\SystemClock;
use PHPUnit\Framework\TestCase;
use Predis\Client;

final class RedisFixture
{
    /**
     * Connect to the Redis service configured for contract tests.
     *
     * @param TestCase $test Test case used for an explicit skip
     * @param string $prefix Driver key prefix
     * @param ClockInterface $clock Test clock
     * @return RedisQueueDriver Connected Redis driver
     */
    public static function driver(
        TestCase $test,
        string $prefix,
        ClockInterface $clock = new SystemClock()
    ): RedisQueueDriver {
        $host = getenv('REDIS_HOST');
        if (!is_string($host) || $host === '') {
            $test->markTestSkipped('REDIS_HOST is not set. Skipping Redis-backed test.');
        }

        $port = getenv('REDIS_PORT') ?: '6379';
        $client = new Client(['scheme' => 'tcp', 'host' => $host, 'port' => (int) $port]);
        try {
            $client->connect();
        } catch (\Throwable $exception) {
            $test->markTestSkipped('Could not connect to Redis: ' . $exception->getMessage());
        }

        return new RedisQueueDriver($client, $prefix, $clock);
    }
}
