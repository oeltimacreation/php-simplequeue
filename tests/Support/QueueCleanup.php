<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Support;

use Oeltima\SimpleQueue\Contract\QueueDriverInterface;
use Oeltima\SimpleQueue\Driver\InMemoryQueueDriver;
use Oeltima\SimpleQueue\Driver\RedisQueueDriver;

final class QueueCleanup
{
    /**
     * Clear notification state for drivers that own an in-memory or Redis queue.
     *
     * Database polling has no separate notification state to clear.
     *
     * @param QueueDriverInterface $driver Queue driver
     * @param string $queue Queue name for Redis
     */
    public static function clear(QueueDriverInterface $driver, string $queue = 'default'): void
    {
        if ($driver instanceof RedisQueueDriver) {
            $driver->clear($queue);
            return;
        }

        if ($driver instanceof InMemoryQueueDriver) {
            $driver->clear();
        }
    }
}
