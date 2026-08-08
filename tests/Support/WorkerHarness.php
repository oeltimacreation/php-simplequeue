<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Support;

use Oeltima\SimpleQueue\Contract\JobHandlerInterface;
use Oeltima\SimpleQueue\Contract\JobStorageInterface;
use Oeltima\SimpleQueue\Contract\QueueDriverInterface;
use Oeltima\SimpleQueue\Driver\InMemoryQueueDriver;
use Oeltima\SimpleQueue\JobRegistry;
use Oeltima\SimpleQueue\QueueManager;
use Oeltima\SimpleQueue\Storage\InMemoryJobStorage;
use Oeltima\SimpleQueue\Worker;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class WorkerHarness
{
    public JobStorageInterface $storage;
    public QueueDriverInterface $driver;
    public JobRegistry $registry;
    public LoggerInterface $logger;
    public QueueManager $queueManager;
    public Worker $worker;

    /**
     * @param JobStorageInterface|null $storage
     * @param QueueDriverInterface|null $driver
     * @param LoggerInterface|null $logger
     * @param array<string, mixed> $options
     * @param string $queue
     */
    public function __construct(
        ?JobStorageInterface $storage = null,
        ?QueueDriverInterface $driver = null,
        ?LoggerInterface $logger = null,
        array $options = [],
        string $queue = 'default'
    ) {
        $this->storage = $storage ?? new InMemoryJobStorage();
        $this->driver = $driver ?? new InMemoryQueueDriver();
        $this->registry = new JobRegistry();
        $this->logger = $logger ?? new NullLogger();

        $this->queueManager = new QueueManager($this->driver);

        $defaultOptions = [
            'lock_file' => null,
            'poll_timeout' => 0,
            'stuck_job_ttl' => 600,
        ];

        $this->worker = new Worker(
            $this->storage,
            $this->queueManager,
            $this->registry,
            $this->logger,
            $queue,
            array_merge($defaultOptions, $options)
        );
    }

    /**
     * @param JobStorageInterface|null $storage
     * @param QueueDriverInterface|null $driver
     * @param LoggerInterface|null $logger
     * @param array<string, mixed> $options
     * @param string $queue
     * @return self
     */
    public static function create(
        ?JobStorageInterface $storage = null,
        ?QueueDriverInterface $driver = null,
        ?LoggerInterface $logger = null,
        array $options = [],
        string $queue = 'default'
    ): self {
        return new self($storage, $driver, $logger, $options, $queue);
    }

    /**
     * @param string $type
     * @param JobHandlerInterface|callable $handler
     * @return self
     */
    public function registerHandler(string $type, JobHandlerInterface|callable $handler): self
    {
        $this->registry->register($type, $handler);
        return $this;
    }

    /**
     * @return bool
     */
    public function processOne(): bool
    {
        return $this->worker->processOne();
    }
}
