<?php

declare(strict_types=1);

use Oeltima\SimpleQueue\JobDispatcher;
use Oeltima\SimpleQueue\QueueManager;
use Oeltima\SimpleQueue\Storage\PdoJobStorage;
use Predis\Client;

require_once __DIR__ . '/../../vendor/autoload.php';

/** @return array{0: PdoJobStorage, 1: QueueManager, 2: JobDispatcher, 3: string} */
function createExampleQueue(): array
{
    $queue = getenv('QUEUE_NAME') ?: 'default';
    $storage = new PdoJobStorage(static fn (): PDO => new PDO(
        getenv('DATABASE_DSN') ?: 'mysql:host=127.0.0.1;dbname=myapp;charset=utf8mb4',
        getenv('DATABASE_USER') ?: 'root',
        getenv('DATABASE_PASSWORD') ?: '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    ));
    $redis = new Client([
        'host' => getenv('REDIS_HOST') ?: '127.0.0.1',
        'port' => (int) (getenv('REDIS_PORT') ?: 6379),
        'read_write_timeout' => -1,
    ]);
    $queues = QueueManager::redis($redis, getenv('QUEUE_PREFIX') ?: 'myapp');

    return [$storage, $queues, new JobDispatcher($storage, $queues), $queue];
}
