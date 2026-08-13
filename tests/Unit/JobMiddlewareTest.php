<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Unit;

use Oeltima\SimpleQueue\Contract\JobContextInterface;
use Oeltima\SimpleQueue\Contract\JobHandlerInterface;
use Oeltima\SimpleQueue\Contract\JobMiddlewareInterface;
use Oeltima\SimpleQueue\Contract\JobStatus;
use Oeltima\SimpleQueue\Driver\InMemoryQueueDriver;
use Oeltima\SimpleQueue\JobDispatcher;
use Oeltima\SimpleQueue\JobRegistry;
use Oeltima\SimpleQueue\QueueManager;
use Oeltima\SimpleQueue\Storage\InMemoryJobStorage;
use Oeltima\SimpleQueue\Worker;
use PHPUnit\Framework\TestCase;

final class JobMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        JobMiddlewareTestState::reset();
    }

    public function testMiddlewareWrapsHandlerInRegistrationOrderAndExposesContext(): void
    {
        $storage = new InMemoryJobStorage();
        $driver = new InMemoryQueueDriver();
        $queueManager = new QueueManager($driver);
        $registry = new JobRegistry();
        $registry->register('middleware.test', JobMiddlewareTestHandler::class);
        $registry->middleware->register(new JobMiddlewareTestRecorder('first'));
        $registry->middleware->register(new JobMiddlewareTestRecorder('second'));
        $dispatcher = new JobDispatcher($storage, $queueManager);

        $jobId = $dispatcher->dispatch('middleware.test', ['message' => 'hello'], 'emails');
        $worker = new Worker(
            $storage,
            $queueManager,
            $registry,
            queue: 'emails',
            options: ['lock_file' => null, 'poll_timeout' => 0]
        );

        self::assertTrue($worker->processOne());
        $job = $storage->find($jobId);

        self::assertNotNull($job);
        self::assertSame(JobStatus::Completed, $job->status);
        self::assertSame(['handled' => true], $job->result);
        self::assertSame(
            ['first.before', 'second.before', 'handler', 'second.after', 'first.after'],
            JobMiddlewareTestState::$events
        );
        self::assertSame(
            [
                [
                    'id' => $jobId,
                    'type' => 'middleware.test',
                    'payload' => ['message' => 'hello'],
                    'queue' => 'emails',
                    'attempts' => 1,
                ],
                [
                    'id' => $jobId,
                    'type' => 'middleware.test',
                    'payload' => ['message' => 'hello'],
                    'queue' => 'emails',
                    'attempts' => 1,
                ],
            ],
            JobMiddlewareTestState::$contexts
        );
    }

    public function testMiddlewareExceptionUsesExistingRetryAndFailurePath(): void
    {
        $storage = new InMemoryJobStorage();
        $driver = new InMemoryQueueDriver();
        $queueManager = new QueueManager($driver);
        $registry = new JobRegistry();
        $registry->register('middleware.failure', JobMiddlewareTestHandler::class);
        $registry->middleware->register(new JobMiddlewareTestFailure());
        $dispatcher = new JobDispatcher($storage, $queueManager);
        $jobId = $dispatcher->dispatch('middleware.failure', [], 'default', 2);
        $worker = new Worker(
            $storage,
            $queueManager,
            $registry,
            queue: 'default',
            options: ['lock_file' => null, 'poll_timeout' => 0, 'retry_base_delay' => 0, 'retry_max_delay' => 0]
        );

        self::assertTrue($worker->processOne());
        $retryJob = $storage->find($jobId);
        self::assertNotNull($retryJob);
        self::assertSame(JobStatus::Pending, $retryJob->status);
        self::assertSame(1, $retryJob->attempts);
        self::assertSame([], JobMiddlewareTestState::$events);

        self::assertTrue($worker->processOne());
        $job = $storage->find($jobId);

        self::assertNotNull($job);
        self::assertSame(JobStatus::Failed, $job->status);
        self::assertSame('Middleware failure', $job->errorMessage);
        self::assertSame([], JobMiddlewareTestState::$events);
        self::assertSame(0, JobMiddlewareTestState::$handlerCalls);
    }

    public function testMiddlewareRegistryStartsEmptyAndCanBeCleared(): void
    {
        $registry = new JobRegistry();
        self::assertSame([], $registry->middleware->all());

        $registry->middleware->register(new JobMiddlewareTestRecorder('one'));
        $registry->middleware->clear();

        self::assertSame([], $registry->middleware->all());
    }
}

final class JobMiddlewareTestState
{
    /** @var list<string> */
    public static array $events = [];

    /** @var list<array{id: int, type: string, payload: array<string, mixed>, queue: string, attempts: int}> */
    public static array $contexts = [];

    public static int $handlerCalls = 0;

    public static function reset(): void
    {
        self::$events = [];
        self::$contexts = [];
        self::$handlerCalls = 0;
    }
}

final class JobMiddlewareTestHandler implements JobHandlerInterface
{
    public function handle(int $jobId, array $payload, ?callable $progressCallback = null): mixed
    {
        JobMiddlewareTestState::$events[] = 'handler';
        JobMiddlewareTestState::$handlerCalls++;

        return ['handled' => true];
    }
}

final class JobMiddlewareTestRecorder implements JobMiddlewareInterface
{
    public function __construct(private readonly string $name)
    {
    }

    public function process(JobContextInterface $context): mixed
    {
        JobMiddlewareTestState::$events[] = $this->name . '.before';
        JobMiddlewareTestState::$contexts[] = [
            'id' => $context->getJobId(),
            'type' => $context->getType(),
            'payload' => $context->getPayload(),
            'queue' => $context->getQueue(),
            'attempts' => $context->getAttempts(),
        ];
        $result = $context->proceed();
        JobMiddlewareTestState::$events[] = $this->name . '.after';

        return $result;
    }
}

final class JobMiddlewareTestFailure implements JobMiddlewareInterface
{
    public function process(JobContextInterface $context): mixed
    {
        throw new \RuntimeException('Middleware failure');
    }
}
