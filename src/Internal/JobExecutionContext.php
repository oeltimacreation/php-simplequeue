<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Internal;

use Oeltima\SimpleQueue\Contract\JobContextInterface;

/**
 * Default worker-owned implementation of the middleware execution context.
 */
final class JobExecutionContext implements JobContextInterface
{
    /**
     * @param int $jobId Job identifier
     * @param string $type Job type identifier
     * @param array<string, mixed> $payload Decoded job payload
     * @param string $queue Queue name
     * @param int $attempts One-based execution attempt number
     * @param \Closure(): mixed $continuation Next middleware or handler
     */
    public function __construct(
        private readonly int $jobId,
        private readonly string $type,
        private readonly array $payload,
        private readonly string $queue,
        private readonly int $attempts,
        private readonly \Closure $continuation
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function getJobId(): int
    {
        return $this->jobId;
    }

    /**
     * {@inheritDoc}
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * {@inheritDoc}
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    /**
     * {@inheritDoc}
     */
    public function getQueue(): string
    {
        return $this->queue;
    }

    /**
     * {@inheritDoc}
     */
    public function getAttempts(): int
    {
        return $this->attempts;
    }

    /**
     * {@inheritDoc}
     */
    public function proceed(): mixed
    {
        return ($this->continuation)();
    }
}
