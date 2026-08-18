<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Internal;

use Oeltima\SimpleQueue\Contract\InfrastructureFailureEvent;
use Oeltima\SimpleQueue\Contract\JobClaimedEvent;
use Oeltima\SimpleQueue\Contract\JobCompletedEvent;
use Oeltima\SimpleQueue\Contract\JobFailedEvent;
use Oeltima\SimpleQueue\Contract\JobLostOwnershipEvent;
use Oeltima\SimpleQueue\Contract\JobRetriedEvent;
use Oeltima\SimpleQueue\Contract\WorkerEventInterface;
use Psr\Log\LoggerInterface;

/**
 * Lazily constructs and delivers legacy worker lifecycle events.
 *
 * @internal
 */
final class WorkerEventEmitter
{
    /**
     * @param LoggerInterface $logger Logger used to isolate listener failures
     * @param mixed $listener Legacy worker event listener
     * @param \Closure|null $mirror Worker-owned listener mirror
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        mixed $listener,
        ?\Closure &$mirror
    ) {
        $this->listener = is_callable($listener) ? \Closure::fromCallable($listener) : null;
        $mirror = $this->listener;
    }

    private ?\Closure $listener;

    /**
     * Update the legacy listener without changing event delivery semantics.
     *
     * @param \Closure|null $listener Legacy worker event listener
     */
    public function setListener(?\Closure $listener): void
    {
        $this->listener = $listener;
    }

    /**
     * Deliver an already-created typed event.
     *
     * @param WorkerEventInterface $event Typed worker event
     */
    public function emit(WorkerEventInterface $event): void
    {
        if ($this->listener === null) {
            return;
        }

        try {
            ($this->listener)($event->getName(), $event->toArray());
        } catch (\Throwable $listenerError) {
            $this->logger->error('Worker event listener threw an exception', [
                'event' => $event->getName(),
                'error' => $listenerError->getMessage()
            ]);
        }
    }

    /**
     * Emit a claimed event when a listener is configured.
     *
     * @param int $jobId Claimed job identifier
     * @param string $type Claimed job type
     * @param float $latency Acquisition latency in milliseconds
     */
    public function claimed(int $jobId, string $type, float $latency): void
    {
        if ($this->listener === null) {
            return;
        }

        $this->emit(new JobClaimedEvent($jobId, $type, $latency));
    }

    /**
     * Emit a completed event when a listener is configured.
     *
     * @param int $jobId Completed job identifier
     * @param string $type Completed job type
     * @param float $durationMs Handler duration in milliseconds
     */
    public function completed(int $jobId, string $type, float $durationMs): void
    {
        if ($this->listener === null) {
            return;
        }

        $this->emit(new JobCompletedEvent($jobId, $type, $durationMs));
    }

    /**
     * Emit a processing-heartbeat infrastructure event when configured.
     *
     * @param int $jobId Affected job identifier
     * @param string $operation Failed infrastructure operation
     */
    public function infrastructureFailure(int $jobId, string $operation): void
    {
        if ($this->listener === null) {
            return;
        }

        $this->emit(new InfrastructureFailureEvent($jobId, $operation));
    }

    /**
     * Emit a retry event when a listener is configured.
     *
     * @param int $jobId Retried job identifier
     * @param string $type Retried job type
     * @param float $durationMs Handler duration in milliseconds
     * @param int $attempts Attempt number after the failure
     * @param string $error Failure message
     */
    public function retried(int $jobId, string $type, float $durationMs, int $attempts, string $error): void
    {
        if ($this->listener === null) {
            return;
        }

        $this->emit(JobRetriedEvent::fromArray([
            'job_id' => $jobId,
            'type' => $type,
            'duration_ms' => $durationMs,
            'attempts' => $attempts,
            'error' => $error,
        ]));
    }

    /**
     * Emit a permanent-failure event when a listener is configured.
     *
     * @param int $jobId Failed job identifier
     * @param string $type Failed job type
     * @param float $durationMs Handler duration in milliseconds
     * @param string $error Failure message
     */
    public function failed(int $jobId, string $type, float $durationMs, string $error): void
    {
        if ($this->listener === null) {
            return;
        }

        $this->emit(new JobFailedEvent($jobId, $type, $durationMs, $error));
    }

    /**
     * Emit a lost-ownership event when a listener is configured.
     *
     * @param int $jobId Lost job identifier
     * @param string $type Lost job type
     * @param string $context Lifecycle operation that lost ownership
     */
    public function lostOwnership(int $jobId, string $type, string $context): void
    {
        if ($this->listener === null) {
            return;
        }

        $this->emit(new JobLostOwnershipEvent($jobId, $type, $context));
    }
}
