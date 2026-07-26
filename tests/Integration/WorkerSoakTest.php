<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Integration;

use Oeltima\SimpleQueue\Contract\JobHandlerInterface;
use Oeltima\SimpleQueue\Contract\JobStatus;
use Oeltima\SimpleQueue\Contract\QueueDriverInterface;
use Oeltima\SimpleQueue\Driver\InMemoryQueueDriver;
use Oeltima\SimpleQueue\Internal\WorkerPolicy;
use Oeltima\SimpleQueue\JobDispatcher;
use Oeltima\SimpleQueue\JobRegistry;
use Oeltima\SimpleQueue\QueueManager;
use Oeltima\SimpleQueue\Storage\InMemoryJobStorage;
use Oeltima\SimpleQueue\Storage\PdoJobStorage;
use Oeltima\SimpleQueue\Tests\DbHelper;
use Oeltima\SimpleQueue\Worker;
use PDO;
use PHPUnit\Framework\TestCase;

final class WorkerSoakTest extends TestCase
{
    public function testRepeatedWorkerRecyclingCompletesWorkWithStableMemory(): void
    {
        $storage = new InMemoryJobStorage();
        $driver = new InMemoryQueueDriver();
        $registry = $this->registry();
        $dispatcher = new JobDispatcher($storage, new QueueManager($driver));
        for ($job = 0; $job < 500; $job++) {
            $dispatcher->dispatch('soak.job', ['sequence' => $job]);
        }
        $memoryBefore = memory_get_usage(true);

        for ($cycle = 0; $cycle < 10; $cycle++) {
            $worker = $this->worker($storage, $driver, $registry, ['max_jobs' => 50]);
            self::assertSame(Worker::EXIT_SUCCESS, $worker->run());
        }
        $drainingWorker = $this->worker($storage, $driver, $registry, ['stop_when_empty' => true]);
        for ($duplicate = 0; $duplicate < 500; $duplicate++) {
            $drainingWorker->processOne();
        }

        gc_collect_cycles();
        self::assertSame(500, $storage->count(JobStatus::Completed));
        self::assertSame([], $driver->getPending('default'));
        self::assertLessThanOrEqual($memoryBefore + 8_388_608, memory_get_usage(true));
    }

    public function testRepeatedReconnectsKeepStorageUsable(): void
    {
        $database = tempnam(sys_get_temp_dir(), 'sq_soak_');
        self::assertNotFalse($database);
        $pdo = new PDO("sqlite:{$database}");
        DbHelper::createSchema($pdo);
        $connections = 0;
        $storage = new PdoJobStorage(static function () use ($database, &$connections): PDO {
            $connections++;
            $connection = new PDO("sqlite:{$database}");
            $connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            return $connection;
        });

        try {
            $jobId = $storage->createJob('soak.reconnect', []);
            for ($cycle = 0; $cycle < 100; $cycle++) {
                $storage->reconnect();
                self::assertSame($jobId, $storage->find($jobId)?->id);
            }
            self::assertSame(101, $connections);
        } finally {
            unlink($database);
        }
    }

    public function testBackoffPolicyRemainsCappedAcrossLongFailureSequence(): void
    {
        $policy = new WorkerPolicy(2, 30);
        $minimum = PHP_INT_MAX;
        $maximum = 0;

        for ($failure = 1; $failure <= 10_000; $failure++) {
            $delay = $policy->backoffDelay($failure);
            $minimum = min($minimum, $delay);
            $maximum = max($maximum, $delay);
        }

        self::assertSame(2, $minimum);
        self::assertSame(30, $maximum);
    }

    public function testSigtermStopsLongRunningWorkerGracefully(): void
    {
        if (!$this->supportsProcessControl()) {
            self::markTestSkipped('Process control is unavailable.');
        }
        $readyFile = tempnam(sys_get_temp_dir(), 'sq_signal_');
        self::assertNotFalse($readyFile);
        unlink($readyFile);
        $processId = pcntl_fork();
        self::assertNotSame(-1, $processId);

        if ($processId === 0) {
            $worker = $this->worker(new InMemoryJobStorage(), $this->idleDriver($readyFile), new JobRegistry());
            exit($worker->run());
        }

        try {
            $this->waitUntilReady($readyFile, $processId);
            self::assertTrue(posix_kill($processId, SIGTERM));
            self::assertSame($processId, pcntl_waitpid($processId, $status));
            self::assertTrue(pcntl_wifexited($status));
            self::assertSame(Worker::EXIT_SUCCESS, pcntl_wexitstatus($status));
        } finally {
            if (file_exists($readyFile)) {
                unlink($readyFile);
            }
        }
    }

    private function supportsProcessControl(): bool
    {
        return !in_array(false, [
            function_exists('pcntl_fork'),
            function_exists('posix_kill'),
            PHP_OS_FAMILY !== 'Windows',
        ], true);
    }

    private function registry(): JobRegistry
    {
        $handler = new class implements JobHandlerInterface {
            public function handle(int $jobId, array $payload, ?callable $progressCallback = null): int
            {
                return $jobId;
            }
        };
        $registry = new JobRegistry();
        $registry->register('soak.job', $handler::class);
        return $registry;
    }

    /** @param array<string, mixed> $options */
    private function worker(
        InMemoryJobStorage $storage,
        QueueDriverInterface $driver,
        JobRegistry $registry,
        array $options = []
    ): Worker {
        return new Worker($storage, new QueueManager($driver), $registry, options: array_merge([
            'lock_file' => null,
            'poll_timeout' => 0,
        ], $options));
    }

    private function idleDriver(string $readyFile): QueueDriverInterface
    {
        return new class ($readyFile) implements QueueDriverInterface {
            public function __construct(private readonly string $readyFile)
            {
            }

            public function isAvailable(): true
            {
                return true;
            }

            public function enqueue(string $queue, int $jobId): void
            {
            }

            public function dequeue(string $queue, int $timeoutSeconds): ?int
            {
                touch($this->readyFile);
                usleep(1_000);
                return null;
            }

            public function ack(string $queue, int $jobId): void
            {
            }

            public function nack(string $queue, int $jobId, int $delaySeconds = 0): void
            {
            }
        };
    }

    private function waitUntilReady(string $readyFile, int $processId): void
    {
        for ($attempt = 0; $attempt < 200; $attempt++) {
            if (file_exists($readyFile)) {
                return;
            }
            usleep(10_000);
        }

        posix_kill($processId, SIGKILL);
        pcntl_waitpid($processId, $status);
        self::fail('Worker did not reach its signal-ready state.');
    }
}
