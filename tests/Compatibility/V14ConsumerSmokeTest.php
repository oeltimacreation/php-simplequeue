<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Compatibility;

use Oeltima\SimpleQueue\Contract\JobHandlerInterface;
use Oeltima\SimpleQueue\Driver\InMemoryQueueDriver;
use Oeltima\SimpleQueue\JobDispatcher;
use Oeltima\SimpleQueue\JobRegistry;
use Oeltima\SimpleQueue\QueueManager;
use Oeltima\SimpleQueue\Storage\InMemoryJobStorage;
use Oeltima\SimpleQueue\Worker;
use PHPUnit\Framework\TestCase;

final class V14ConsumerSmokeTest extends TestCase
{
    public function testV14PublicConstructionAndLifecycleRemainUsable(): void
    {
        $storage = new InMemoryJobStorage();
        $manager = new QueueManager(new InMemoryQueueDriver());
        $registry = new JobRegistry();
        $registry->register('compatibility.noop', V14NoopHandler::class);

        $dispatcher = new JobDispatcher($storage, $manager);
        $jobId = $dispatcher->dispatch('compatibility.noop', ['value' => 'ok']);

        $worker = new Worker($storage, $manager, $registry, queue: 'default', options: [
            'stop_when_empty' => true,
            'lock_file' => null,
        ]);

        self::assertSame($jobId, $dispatcher->getStatus($jobId)->id);
        self::assertSame(Worker::EXIT_SUCCESS, $worker->run());
        self::assertSame('completed', $dispatcher->getStatus($jobId)?->status->value);
        self::assertSame($manager->driver(), $dispatcher->getQueueManager()->driver());
    }
}
final class V14NoopHandler implements JobHandlerInterface
{
    public function handle(int $jobId, array $payload, ?callable $progressCallback = null): mixed
    {
        return $payload;
    }
}
