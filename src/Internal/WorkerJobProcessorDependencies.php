<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Internal;

use Oeltima\SimpleQueue\Contract\ClockInterface;
use Oeltima\SimpleQueue\Contract\JobStorageInterface;
use Oeltima\SimpleQueue\JobRegistry;
use Psr\Log\LoggerInterface;

/** Typed dependency bundle for one worker's job processor. @internal */
final readonly class WorkerJobProcessorDependencies
{
    public JobStorageInterface $storage;
    public JobRegistry $registry;
    public LoggerInterface $logger;
    public string $queue;
    public WorkerPolicy $policy;
    public ClockInterface $clock;
    public WorkerEventEmitter $eventEmitter;
    public float $progressInterval;
    public float $heartbeatInterval;

    /**
     * @param array{
     *     storage: JobStorageInterface,
     *     registry: JobRegistry,
     *     logger: LoggerInterface,
     *     queue: string,
     *     policy: WorkerPolicy,
     *     clock: ClockInterface,
     *     progressInterval?: float,
     *     heartbeatInterval?: float,
     *     eventEmitter: WorkerEventEmitter
     * } $dependencies
     */
    public function __construct(array $dependencies)
    {
        $this->progressInterval = $dependencies['progressInterval'] ?? 0.0;
        $this->heartbeatInterval = $dependencies['heartbeatInterval'] ?? 15.0;
        $this->storage = $dependencies['storage'];
        $this->registry = $dependencies['registry'];
        $this->logger = $dependencies['logger'];
        $this->queue = $dependencies['queue'];
        $this->policy = $dependencies['policy'];
        $this->clock = $dependencies['clock'];
        $this->eventEmitter = $dependencies['eventEmitter'];
    }
}
