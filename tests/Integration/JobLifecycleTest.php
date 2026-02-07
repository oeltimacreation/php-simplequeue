<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Integration;

use Oeltima\SimpleQueue\Contract\JobHandlerInterface;
use Oeltima\SimpleQueue\Driver\InMemoryQueueDriver;
use Oeltima\SimpleQueue\JobDispatcher;
use Oeltima\SimpleQueue\JobRegistry;
use Oeltima\SimpleQueue\QueueManager;
use Oeltima\SimpleQueue\Storage\InMemoryJobStorage;
use Oeltima\SimpleQueue\Worker;
use PHPUnit\Framework\TestCase;

class JobLifecycleTest extends TestCase
{
    private InMemoryJobStorage $storage;
    private InMemoryQueueDriver $driver;
    private QueueManager $queueManager;
    private JobDispatcher $dispatcher;
    private JobRegistry $registry;
    private Worker $worker;

    protected function setUp(): void
    {
        $this->storage = new InMemoryJobStorage();
        $this->driver = new InMemoryQueueDriver();
        $this->queueManager = new QueueManager($this->driver);
        $this->registry = new JobRegistry();
        $this->dispatcher = new JobDispatcher($this->storage, $this->queueManager);
        $this->worker = new Worker(
            $this->storage,
            $this->queueManager,
            $this->registry,
            null,
            'default',
            ['lock_file' => null, 'poll_timeout' => 0]
        );
    }

    public function testFullJobLifecycleDispatchProcessComplete(): void
    {
        $handler = new class implements JobHandlerInterface {
            public function handle(int $jobId, array $payload, ?callable $progressCallback = null): mixed
            {
                return ['result' => 'success'];
            }
        };

        $this->registry->register('test.job', get_class($handler));

        $jobId = $this->dispatcher->dispatch('test.job', ['key' => 'value']);

        $job = $this->storage->find($jobId);
        $this->assertNotNull($job);
        $this->assertSame('pending', $job->status);

        $pending = $this->driver->getPending('default');
        $this->assertContains($jobId, $pending);

        $processed = $this->worker->processOne();
        $this->assertTrue($processed);

        $job = $this->storage->find($jobId);
        $this->assertNotNull($job);
        $this->assertSame('completed', $job->status);
        $this->assertSame(['result' => 'success'], $job->result);

        $this->assertEmpty($this->driver->getPending('default'));
        $this->assertEmpty($this->driver->getProcessing('default'));
    }

    public function testFullJobLifecycleWithProgress(): void
    {
        $handler = new class implements JobHandlerInterface {
            public function handle(int $jobId, array $payload, ?callable $progressCallback = null): mixed
            {
                if ($progressCallback !== null) {
                    $progressCallback(50, 'Halfway done');
                    $progressCallback(100, 'Complete');
                }
                return ['processed' => true];
            }
        };

        $this->registry->register('test.progress', get_class($handler));

        $jobId = $this->dispatcher->dispatch('test.progress', ['data' => 'test']);

        $this->worker->processOne();

        $job = $this->storage->find($jobId);
        $this->assertNotNull($job);
        $this->assertSame('completed', $job->status);
        $this->assertSame(['processed' => true], $job->result);
    }

    public function testJobFailureAndRetry(): void
    {
        $handler = new class implements JobHandlerInterface {
            public static int $callCount = 0;

            public function handle(int $jobId, array $payload, ?callable $progressCallback = null): mixed
            {
                self::$callCount++;
                if (self::$callCount === 1) {
                    throw new \RuntimeException('Temporary failure');
                }
                return ['retried' => true];
            }
        };

        $handler::$callCount = 0;
        $this->registry->register('test.retry', get_class($handler));

        $this->worker = new Worker(
            $this->storage,
            $this->queueManager,
            $this->registry,
            null,
            'default',
            [
                'lock_file' => null,
                'poll_timeout' => 0,
                'retry_base_delay' => 0,
                'retry_max_delay' => 0,
            ]
        );

        $jobId = $this->dispatcher->dispatch('test.retry', ['key' => 'value'], 'default', 3);

        $this->worker->processOne();

        $job = $this->storage->find($jobId);
        $this->assertNotNull($job);
        $this->assertSame('pending', $job->status);
        $this->assertSame(1, $job->attempts);

        $this->worker->processOne();

        $job = $this->storage->find($jobId);
        $this->assertNotNull($job);
        $this->assertSame('completed', $job->status);
        $this->assertSame(['retried' => true], $job->result);
        $this->assertSame(2, $handler::$callCount);
    }

    public function testBatchDispatch(): void
    {
        $handler = new class implements JobHandlerInterface {
            public function handle(int $jobId, array $payload, ?callable $progressCallback = null): mixed
            {
                return ['item' => $payload['item']];
            }
        };

        $this->registry->register('test.batch', get_class($handler));

        $payloads = [
            ['item' => 'a'],
            ['item' => 'b'],
            ['item' => 'c'],
        ];

        $jobIds = $this->dispatcher->dispatchBatch('test.batch', $payloads);
        $this->assertCount(3, $jobIds);

        foreach ($jobIds as $jobId) {
            $job = $this->storage->find($jobId);
            $this->assertSame('pending', $job->status);
        }

        for ($i = 0; $i < 3; $i++) {
            $this->assertTrue($this->worker->processOne());
        }

        foreach ($jobIds as $jobId) {
            $job = $this->storage->find($jobId);
            $this->assertSame('completed', $job->status);
        }
    }

    public function testIdempotentDispatch(): void
    {
        $handler = new class implements JobHandlerInterface {
            public function handle(int $jobId, array $payload, ?callable $progressCallback = null): mixed
            {
                return ['done' => true];
            }
        };

        $this->registry->register('test.idempotent', get_class($handler));

        $result1 = $this->dispatcher->dispatchIdempotent('test.idempotent', ['key' => 'value'], 'req-1');
        $this->assertTrue($result1['created']);
        $firstJobId = $result1['job_id'];

        $result2 = $this->dispatcher->dispatchIdempotent('test.idempotent', ['key' => 'value'], 'req-1');
        $this->assertFalse($result2['created']);
        $this->assertSame($firstJobId, $result2['job_id']);

        $this->worker->processOne();

        $job = $this->storage->find($firstJobId);
        $this->assertSame('completed', $job->status);

        $result3 = $this->dispatcher->dispatchIdempotent('test.idempotent', ['key' => 'value2'], 'req-1');
        $this->assertTrue($result3['created']);
        $this->assertNotSame($firstJobId, $result3['job_id']);
    }
}
