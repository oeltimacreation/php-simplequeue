<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Internal;

use Oeltima\SimpleQueue\Contract\ControlledJobContextInterface;
use Oeltima\SimpleQueue\Contract\JobData;

/**
 * Default worker-owned implementation of the middleware execution context.
 */
final class JobExecutionContext implements ControlledJobContextInterface
{
    private bool $proceeded = false;

    /**
     * @param JobData $job Claimed job data
     * @param \Closure(): mixed $continuation Next middleware or handler
     */
    public function __construct(
        private readonly JobData $job,
        private readonly \Closure $continuation,
        private readonly ?WorkerExecutionControl $control = null
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function getJobId(): int
    {
        return $this->job->id;
    }

    /**
     * {@inheritDoc}
     */
    public function getType(): string
    {
        return $this->job->type;
    }

    /**
     * {@inheritDoc}
     */
    public function getPayload(): array
    {
        return $this->job->payload;
    }

    /**
     * {@inheritDoc}
     */
    public function getQueue(): string
    {
        return $this->job->queue;
    }

    /**
     * {@inheritDoc}
     */
    public function getAttempts(): int
    {
        return $this->job->attempts + 1;
    }

    /** @inheritDoc */
    public function reportProgress(int $percent, ?string $message = null): void
    {
        $this->executionControl()->reportProgress($percent, $message);
    }

    /** @inheritDoc */
    public function heartbeat(): void
    {
        $this->executionControl()->heartbeat();
    }

    /** @inheritDoc */
    public function assertOwnership(): void
    {
        $this->executionControl()->assertOwnership();
    }

    private function executionControl(): WorkerExecutionControl
    {
        return $this->control ?? throw new \LogicException('Execution controls require a worker-owned context');
    }

    /**
     * {@inheritDoc}
     */
    public function proceed(): mixed
    {
        if ($this->proceeded) {
            throw new \LogicException('Job context proceed() must be called exactly once');
        }
        $this->proceeded = true;
        return ($this->continuation)();
    }
}
