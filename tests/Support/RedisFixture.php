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
     * @param string $prefix Driver key prefix
     * @param ClockInterface $clock Test clock
     * @return RedisQueueDriver Connected Redis driver
     */
    public static function driver(
        string $prefix,
        ClockInterface $clock = new SystemClock()
    ): RedisQueueDriver {
        $host = getenv('REDIS_HOST');
        $required = getenv('SIMPLEQUEUE_REQUIRED_QUEUE_SERVICE');
        $requiredService = is_string($required) && $required !== '';
        if (!is_string($host) || $host === '') {
            if ($requiredService) {
                TestCase::fail('REDIS_HOST is required for the configured ' . $required . ' lane.');
            }
            TestCase::markTestSkipped('REDIS_HOST is not set. Skipping Redis-backed test.');
        }

        $portValue = getenv('REDIS_PORT');
        $port = is_string($portValue) && $portValue !== '' ? $portValue : '6379';
        $client = new Client(['scheme' => 'tcp', 'host' => $host, 'port' => (int) $port]);
        try {
            $client->connect();
        } catch (\Throwable $exception) {
            if ($requiredService) {
                TestCase::fail('Could not connect to configured ' . $required . ': ' . $exception->getMessage());
            }
            TestCase::markTestSkipped('Could not connect to Redis: ' . $exception->getMessage());
        }

        return new RedisQueueDriver($client, $prefix, $clock);
    }
}
