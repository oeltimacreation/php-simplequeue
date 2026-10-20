<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Oeltima\SimpleQueue\MaintenanceOptions;
use Oeltima\SimpleQueue\QueueMaintenance;

[$storage, $queues, , $queue] = createExampleQueue();
$maintenance = new QueueMaintenance($storage, $queues->driver());
$options = new MaintenanceOptions();
$cursor = null;
$running = true;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use (&$running): void {
        $running = false;
    });
    pcntl_signal(SIGINT, static function () use (&$running): void {
        $running = false;
    });
}
while ($running) {
    try {
        $result = $maintenance->run($queue, $options, $cursor);
        $cursor = $result->nextCursor;
        echo json_encode($result, JSON_THROW_ON_ERROR) . PHP_EOL;
    } catch (Throwable $exception) {
        fwrite(STDERR, 'Maintenance failed: ' . $exception::class . PHP_EOL);
    }
    usleep(1000000);
}
