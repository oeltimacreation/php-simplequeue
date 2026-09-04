<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Internal;

use Oeltima\SimpleQueue\Contract\ClaimedJob;
use Oeltima\SimpleQueue\Contract\ClockInterface;
use Oeltima\SimpleQueue\Contract\InfrastructureFailureEvent;
use Oeltima\SimpleQueue\Contract\JobCompletedEvent;
use Oeltima\SimpleQueue\Contract\JobFailedEvent;
use Oeltima\SimpleQueue\Contract\JobLostOwnershipEvent;
use Oeltima\SimpleQueue\Contract\JobRetriedEvent;
use Oeltima\SimpleQueue\Contract\JobStorageInterface;
use Oeltima\SimpleQueue\Contract\QueueDriverInterface;
use Oeltima\SimpleQueue\Contract\SupportsProcessingHeartbeat;
use Oeltima\SimpleQueue\Exception\HandlerNotFoundException;
use Oeltima\SimpleQueue\Exception\SerializationException;
use Oeltima\SimpleQueue\JobRegistry;
use Psr\Log\LoggerInterface;

/**
 * Applies the durable outcome flow for one claimed job.
 *
 * @internal
 */
final readonly class WorkerJobProcessor
{
    public function __construct(
        private JobStorageInterface $storage,
        private JobRegistry $registry,
        private LoggerInterface $logger,
        private string $queue,
        private WorkerPolicy $policy,
        private ClockInterface $clock,
        private WorkerEventEmitter $eventEmitter
    ) {
    }

    /**
     * Execute a claimed job and apply exactly one fenced durable outcome.
     *
     * @param ClaimedJob $claim Claimed job
     * @param QueueDriverInterface $driver Queue driver
     */
    public function process(ClaimedJob $claim, QueueDriverInterface $driver): void
    {
        $job = $claim->job;
        $this->logger->info('Processing job', [
            'job_id' => $job->id,
            'type' => $job->type,
            'attempts' => $job->attempts + 1,
        ]);
        $started = $this->clock->monotonic();

        try {
            $result = $this->executeHandler($claim, $driver);
        } catch (WorkerOwnershipLost) {
            return;
        } catch (\Throwable $handlerException) {
            $this->handleJobFailure($claim, $handlerException, $driver, $this->durationSince($started));
            return;
        }

        $durationMs = $this->durationSince($started);
        try {
            $completed = $this->storage->markCompleted($claim, $result);
        } catch (SerializationException $exception) {
            $this->handleResultSerializationFailure($claim, $driver, $exception, $durationMs);
            return;
        }
        $this->handleJobCompletion($claim, $driver, $completed, $durationMs);
    }

    /**
     * Emit lost ownership for Worker-managed transitions outside handler execution.
     *
     * @param ClaimedJob $claim Claimed job
     * @param string $context Lifecycle operation that lost ownership
     */
    public function emitLostOwnership(ClaimedJob $claim, string $context): void
    {
        if ($this->eventEmitter->isListening()) {
            $this->eventEmitter->emit(new JobLostOwnershipEvent($claim->job->id, $claim->job->type, $context));
        }
    }

    private function executeHandler(ClaimedJob $claim, QueueDriverInterface $driver): mixed
    {
        $job = $claim->job;
        if (!$this->registry->has($job->type)) {
            throw HandlerNotFoundException::forType($job->type);
        }
        $handler = $this->registry->get($job->type);

        $progressCallback = function (int $percent, ?string $message = null) use ($claim, $driver): void {
            $updated = $this->storage->updateProgress($claim, $percent, $message);
            if (!$updated) {
                $this->emitLostOwnership($claim, 'progress');
                throw new WorkerOwnershipLost('Lost job ownership during progress update');
            }
            if (!$driver instanceof SupportsProcessingHeartbeat) {
                return;
            }

            try {
                $driver->heartbeatProcessing($this->queue, $claim->job->id);
            } catch (\Throwable $exception) {
                // Durable fencing still protects completion, so this notification heartbeat is non-fatal.
                $this->logger->error('Failed to refresh queue processing visibility', [
                    'job_id' => $claim->job->id,
                    'error' => $exception->getMessage(),
                ]);
                $this->emitInfrastructureFailure($claim);
            }
        };

        return JobMiddlewareRunner::run($this->registry->middleware->all(), $claim, $handler, $progressCallback);
    }

    private function handleJobCompletion(
        ClaimedJob $claim,
        QueueDriverInterface $driver,
        bool $completed,
        float $durationMs
    ): void {
        $job = $claim->job;
        if ($this->policy->lostOwnership($completed)) {
            $this->logger->warning('Lost job ownership before completion ack', ['job_id' => $job->id]);
            $this->emitLostOwnership($claim, 'complete');
            return;
        }

        $this->logger->info('Job completed', [
            'job_id' => $job->id,
            'type' => $job->type,
            'duration_seconds' => round($durationMs / 1000.0, 3),
        ]);
        $this->emitCompleted($claim, $durationMs);
        $this->ack($driver, $claim, 'completed');
    }

    private function handleResultSerializationFailure(
        ClaimedJob $claim,
        QueueDriverInterface $driver,
        SerializationException $exception,
        float $durationMs
    ): void {
        // Never rerun a handler merely to recreate its result.
        $marked = $this->storage->markFailed($claim, $exception->getMessage(), $this->truncateTrace($exception));
        if ($this->policy->lostOwnership($marked)) {
            $this->emitLostOwnership($claim, 'result_serialization');
            return;
        }

        $this->logger->error('Job result serialization failed after handler completion', [
            'job_id' => $claim->job->id,
            'duration_ms' => $durationMs,
            'error' => $exception->getMessage(),
        ]);
        $this->emitFailed($claim, $durationMs, $exception);
        $this->ack($driver, $claim, 'serialization-failed');
    }

    private function handleJobFailure(
        ClaimedJob $claim,
        \Throwable $exception,
        QueueDriverInterface $driver,
        float $durationMs
    ): void {
        $job = $claim->job;
        $attempts = $job->attempts + 1;
        $this->logger->error('Job failed', [
            'job_id' => $job->id,
            'type' => $job->type,
            'attempts' => $attempts,
            'max_attempts' => $job->maxAttempts,
            'duration_seconds' => round($durationMs / 1000.0, 3),
            'error' => $exception->getMessage(),
        ]);

        if ($this->policy->retryDecision($attempts, $job->maxAttempts)->shouldRetry()) {
            $this->retryFailedJob($claim, $exception, $driver, $durationMs);
            return;
        }
        $this->failJobPermanently($claim, $exception, $driver, $durationMs);
    }

    private function retryFailedJob(
        ClaimedJob $claim,
        \Throwable $exception,
        QueueDriverInterface $driver,
        float $durationMs
    ): void {
        $attempts = $claim->job->attempts + 1;
        $delay = $this->policy->retryDelay($attempts);
        $scheduled = $this->storage->scheduleRetry($claim, $attempts, $delay, $exception->getMessage());
        if ($this->policy->lostOwnership($scheduled)) {
            $this->logger->warning('Lost job ownership before retry scheduling', ['job_id' => $claim->job->id]);
            $this->emitLostOwnership($claim, 'retry');
            return;
        }

        $this->logger->info('Job scheduled for retry', [
            'job_id' => $claim->job->id,
            'attempts' => $attempts,
            'delay_seconds' => $delay,
        ]);
        $this->emitRetried($claim, $durationMs, $exception);
        try {
            $driver->nack($this->queue, $claim->job->id, $delay);
        } catch (\Throwable $nackException) {
            $this->logger->error('Failed to nack retried job', [
                'job_id' => $claim->job->id,
                'operation' => 'nack',
                'error' => $nackException->getMessage(),
            ]);
            throw $nackException;
        }
    }

    private function failJobPermanently(
        ClaimedJob $claim,
        \Throwable $exception,
        QueueDriverInterface $driver,
        float $durationMs
    ): void {
        $marked = $this->storage->markFailed(
            $claim,
            $exception->getMessage(),
            $this->truncateTrace($exception)
        );
        if ($this->policy->lostOwnership($marked)) {
            $this->logger->warning('Lost job ownership before marking failed', ['job_id' => $claim->job->id]);
            $this->emitLostOwnership($claim, 'fail');
            return;
        }

        $this->emitFailed($claim, $durationMs, $exception);
        $this->ack($driver, $claim, 'failed');
    }

    private function ack(QueueDriverInterface $driver, ClaimedJob $claim, string $outcome): void
    {
        try {
            $driver->ack($this->queue, $claim->job->id);
        } catch (\Throwable $exception) {
            $this->logger->error(sprintf('Failed to ack %s job', $outcome), [
                'job_id' => $claim->job->id,
                'operation' => 'ack',
                'error' => $exception->getMessage(),
            ]);
            throw $exception;
        }
    }

    private function emitCompleted(ClaimedJob $claim, float $durationMs): void
    {
        if ($this->eventEmitter->isListening()) {
            $this->eventEmitter->emit(new JobCompletedEvent($claim->job->id, $claim->job->type, $durationMs));
        }
    }

    private function emitInfrastructureFailure(ClaimedJob $claim): void
    {
        if ($this->eventEmitter->isListening()) {
            $this->eventEmitter->emit(new InfrastructureFailureEvent($claim->job->id, 'processing_heartbeat'));
        }
    }

    private function emitRetried(ClaimedJob $claim, float $durationMs, \Throwable $exception): void
    {
        if (!$this->eventEmitter->isListening()) {
            return;
        }
        $this->eventEmitter->emit(JobRetriedEvent::fromArray([
            'job_id' => $claim->job->id,
            'type' => $claim->job->type,
            'duration_ms' => $durationMs,
            'attempts' => $claim->job->attempts + 1,
            'error' => $exception->getMessage(),
        ]));
    }

    private function emitFailed(ClaimedJob $claim, float $durationMs, \Throwable $exception): void
    {
        if ($this->eventEmitter->isListening()) {
            $this->eventEmitter->emit(new JobFailedEvent(
                $claim->job->id,
                $claim->job->type,
                $durationMs,
                $exception->getMessage()
            ));
        }
    }

    private function durationSince(float $started): float
    {
        return ($this->clock->monotonic() - $started) * 1000.0;
    }

    private function truncateTrace(\Throwable $exception, int $maxLength = 4000): string
    {
        $trace = $exception->getTraceAsString();
        return strlen($trace) > $maxLength
            ? substr($trace, 0, $maxLength) . "\n... [truncated]"
            : $trace;
    }
}
