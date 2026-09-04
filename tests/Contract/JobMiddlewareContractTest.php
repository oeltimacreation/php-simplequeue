<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Contract;

use Oeltima\SimpleQueue\Contract\JobContextInterface;
use Oeltima\SimpleQueue\Contract\JobHandlerInterface;
use Oeltima\SimpleQueue\Contract\JobMiddlewareInterface;
use Oeltima\SimpleQueue\Contract\JobStatus;
use Oeltima\SimpleQueue\Contract\JobStorageInterface;
use Oeltima\SimpleQueue\Contract\QueueDriverInterface;
use Oeltima\SimpleQueue\Driver\DatabaseQueueDriver;
use Oeltima\SimpleQueue\Driver\InMemoryQueueDriver;
use Oeltima\SimpleQueue\JobDispatcher;
use Oeltima\SimpleQueue\JobRegistry;
use Oeltima\SimpleQueue\QueueManager;
use Oeltima\SimpleQueue\Storage\InMemoryJobStorage;
use Oeltima\SimpleQueue\Tests\Support\QueueCleanup;
use Oeltima\SimpleQueue\Tests\Support\RedisFixture;
use Oeltima\SimpleQueue\Tests\Support\SqliteFixture;
use Oeltima\SimpleQueue\Worker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

enum MiddlewareBackend
{
    case InMemory;
    case Database;
    case Redis;
}

final class JobMiddlewareContractTest extends TestCase
{
    /** @return iterable<string, array{MiddlewareBackend}> */
    public static function backends(): iterable
    {
        yield 'in-memory' => [MiddlewareBackend::InMemory];
        yield 'database' => [MiddlewareBackend::Database];
        yield 'Redis' => [MiddlewareBackend::Redis];
    }

    #[DataProvider('backends')]
    public function testMiddlewareWrapsRealClaimAndCompletionCycle(MiddlewareBackend $backend): void
    {
        [$storage, $driver] = $this->backend($backend);
        $queue = 'middleware-contract-' . bin2hex(random_bytes(4));
        MiddlewareContractState::reset();

        try {
            $this->runCycle($storage, $driver, $queue);
        } finally {
            QueueCleanup::clear($driver, $queue);
        }
    }

    /**
     * @param JobStorageInterface $storage
     * @param QueueDriverInterface $driver
     * @param string $queue
     */
    private function runCycle(JobStorageInterface $storage, QueueDriverInterface $driver, string $queue): void
    {
        $queueManager = new QueueManager($driver);
        $registry = new JobRegistry();
        $registry->register('contract.middleware', MiddlewareContractHandler::class);
        $registry->middleware->register(new MiddlewareContractProbe());
        $dispatcher = new JobDispatcher($storage, $queueManager);
        $jobId = $dispatcher->dispatch('contract.middleware', ['key' => 'value'], $queue);
        $worker = new Worker(
            $storage,
            $queueManager,
            $registry,
            queue: $queue,
            options: ['lock_file' => null, 'poll_timeout' => 0]
        );

        self::assertTrue($worker->processOne());
        $job = $storage->find($jobId);

        self::assertNotNull($job);
        self::assertSame(JobStatus::Completed, $job->status);
        self::assertSame(['done' => true], $job->result);
        self::assertSame(['before', 'handler', 'after'], MiddlewareContractState::$events);
    }

    /**
     * @return array{JobStorageInterface, QueueDriverInterface}
     */
    private function backend(MiddlewareBackend $backend): array
    {
        if ($backend === MiddlewareBackend::InMemory) {
            return [new InMemoryJobStorage(), new InMemoryQueueDriver()];
        }

        if ($backend === MiddlewareBackend::Database) {
            $storage = SqliteFixture::createStorage('middleware_jobs');
            return [$storage, new DatabaseQueueDriver($storage, 50)];
        }

        return [new InMemoryJobStorage(), RedisFixture::driver('middleware-contract')];
    }
}

final class MiddlewareContractState
{
    /** @var list<string> */
    public static array $events = [];

    public static function reset(): void
    {
        self::$events = [];
    }
}

final class MiddlewareContractHandler implements JobHandlerInterface
{
    public function handle(int $jobId, array $payload, ?callable $progressCallback = null): mixed
    {
        MiddlewareContractState::$events[] = 'handler';

        return ['done' => true];
    }
}

final class MiddlewareContractProbe implements JobMiddlewareInterface
{
    public function process(JobContextInterface $context): mixed
    {
        MiddlewareContractState::$events[] = 'before';
        self::assertContext($context);
        $result = $context->proceed();
        MiddlewareContractState::$events[] = 'after';

        return $result;
    }

    private static function assertContext(JobContextInterface $context): void
    {
        match (true) {
            $context->getJobId() < 1 => throw new \LogicException('Middleware context has an invalid job ID.'),
            $context->getType() !== 'contract.middleware' => throw new \LogicException('Middleware context has an invalid job type.'),
            $context->getPayload() !== ['key' => 'value'] => throw new \LogicException('Middleware context has an invalid payload.'),
            $context->getQueue() === '' => throw new \LogicException('Middleware context has an empty queue.'),
            $context->getAttempts() !== 1 => throw new \LogicException('Middleware context was not populated from the claim.'),
            default => null,
        };
    }
}
