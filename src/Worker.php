<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue;

use Oeltima\SimpleQueue\Contract\ClockInterface;
use Oeltima\SimpleQueue\Contract\ClaimedJob;
use Oeltima\SimpleQueue\Contract\InfrastructureFailureEvent;
use Oeltima\SimpleQueue\Contract\JobClaimedEvent;
use Oeltima\SimpleQueue\Contract\JobCompletedEvent;
use Oeltima\SimpleQueue\Contract\JobStorageInterface;
use Oeltima\SimpleQueue\Contract\JobFailedEvent;
use Oeltima\SimpleQueue\Contract\JobLostOwnershipEvent;
use Oeltima\SimpleQueue\Contract\JobRetriedEvent;
use Oeltima\SimpleQueue\Contract\QueueDriverInterface;
use Oeltima\SimpleQueue\Contract\SupportsDelayedJobs;
use Oeltima\SimpleQueue\Contract\SupportsStaleRecovery;
use Oeltima\SimpleQueue\Contract\SupportsProcessingHeartbeat;
use Oeltima\SimpleQueue\Contract\SupportsWorkerId;
use Oeltima\SimpleQueue\Contract\SupportsTimeoutValidation;
use Oeltima\SimpleQueue\Contract\SupportsClaimedDequeue;
use Oeltima\SimpleQueue\Contract\WorkerEventInterface;
use Oeltima\SimpleQueue\Exception\HandlerNotFoundException;
use Oeltima\SimpleQueue\Exception\SerializationException;
use Oeltima\SimpleQueue\Internal\JobMiddlewareRunner;
use Oeltima\SimpleQueue\Internal\WorkerLoopFailureHandler;
use Oeltima\SimpleQueue\Internal\WorkerPolicy;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Background worker that processes jobs from the queue.
 *
 * The worker runs in a loop, fetching and processing jobs until
 * it receives a shutdown signal or is manually stopped.
 */
final class Worker
{
    public const EXIT_SUCCESS = 0;
    public const EXIT_ERROR = 1;
    public const EXIT_LOCK_UNAVAILABLE = 2;

    private LoggerInterface $logger;
    private string $workerId;
    private bool $shouldRun = true;
    /** @var resource|null */
    private $lockHandle = null;
    private ?string $lockFile;

    private readonly WorkerOptions $options;
    private readonly ClockInterface $clock;
    private readonly WorkerPolicy $policy;
    private readonly WorkerLoopFailureHandler $loopFailureHandler;
    private int $processedJobsCount = 0;
    private float $startTime = 0.0;
    private float $lastPromoteTime = 0.0;
    private float $lastRecoveryTime = 0.0;
    private ?int $reconcileCursor = null;

    /** @var (callable(string, array<string, mixed>): void)|null */
    private $eventListener = null;

    /**
     * @param JobStorageInterface $storage Job storage implementation
     * @param QueueManager $queueManager Queue manager instance
     * @param JobRegistry $registry Job handler registry
     * @param LoggerInterface|null $logger PSR-3 logger (optional)
     * @param string $queue Queue name to process
     * @param array<string, mixed>|WorkerOptions $options Worker options
     */
    public function __construct(
        private readonly JobStorageInterface $storage,
        private readonly QueueManager $queueManager,
        private readonly JobRegistry $registry,
        ?LoggerInterface $logger = null,
        private readonly string $queue = 'default',
        array|WorkerOptions $options = []
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->workerId = $this->generateWorkerId();

        $driver = $this->queueManager->driver();
        if ($driver instanceof SupportsWorkerId) {
            $driver->setWorkerId($this->workerId);
        }

        $this->options = $options instanceof WorkerOptions ? $options : WorkerOptions::fromArray($options);
        $this->lockFile = $this->resolveLockFile($queue, $options);
        $this->policy = new WorkerPolicy($this->options->retryBaseDelay, $this->options->retryMaxDelay);
        $this->loopFailureHandler = new WorkerLoopFailureHandler($this->logger, $this->policy);
        $this->clock = $this->options->clock ?? new SystemClock();

        if ($driver instanceof SupportsTimeoutValidation) {
            $driver->validateTimeout($this->options->pollTimeout);
        }

        if (is_callable($this->options->eventListener)) {
            $this->eventListener = $this->options->eventListener;
        }
    }

    /**
     * @param array<string, mixed>|WorkerOptions $options
     */
    private function resolveLockFile(string $queue, array|WorkerOptions $options): ?string
    {
        if ($options instanceof WorkerOptions) {
            return $options->lockFile ?? sprintf(
                '/tmp/simplequeue-worker-%s.lock',
                preg_replace('/[^a-zA-Z0-9_-]/', '', $queue)
            );
        }
        if (array_key_exists('lock_file', $options)) {
            return $this->options->lockFile;
        }
        return sprintf(
            '/tmp/simplequeue-worker-%s.lock',
            preg_replace('/[^a-zA-Z0-9_-]/', '', $queue)
        );
    }

    public static function withOptions(
        JobStorageInterface $storage,
        QueueManager $queueManager,
        JobRegistry $registry,
        WorkerOptions $options,
        ?LoggerInterface $logger = null,
        string $queue = 'default'
    ): self {
        return new self($storage, $queueManager, $registry, $logger, $queue, $options);
    }

    /**
     * Set a listener for worker lifecycle events.
     *
     * @param callable(string, array<string, mixed>): void $listener
     */
    public function setEventListener(callable $listener): void
    {
        $this->eventListener = $listener;
    }

    /**
     * Emit a typed event to the registered legacy listener.
     *
     * @param WorkerEventInterface $event Typed worker event
     */
    private function emit(WorkerEventInterface $event): void
    {
        if ($this->eventListener !== null) {
            try {
                ($this->eventListener)($event->getName(), $event->toArray());
            } catch (\Throwable $listenerError) {
                $this->logger->error('Worker event listener threw an exception', [
                    'event' => $event->getName(),
                    'error' => $listenerError->getMessage()
                ]);
            }
        }
    }

    /**
     * Run the worker loop.
     *
     * This method blocks until the worker is stopped via signal
     * or the stop() method is called.
     *
     * @return int Exit code
     */
    public function run(): int
    {
        $this->logger->info('Worker starting', ['worker_id' => $this->workerId, 'queue' => $this->queue]);

        if (!$this->acquireLock()) {
            $this->logger->error('Failed to acquire singleton lock. Another worker may be running.');
            return self::EXIT_LOCK_UNAVAILABLE;
        }

        try {
            $this->initializeRun();
            $this->runLoop();
        } catch (\Throwable $e) {
            $this->logger->critical('Worker encountered a fatal error', [
                'error' => $e->getMessage(),
            ]);
            return self::EXIT_ERROR;
        } finally {
            $this->releaseLock();
        }

        $this->logger->info('Worker stopped gracefully', ['worker_id' => $this->workerId]);
        return self::EXIT_SUCCESS;
    }

    private function initializeRun(): void
    {
        $this->registerSignalHandlers();
        $this->recoverStaleJobs();
        $this->reconcileDbAndRedis();
        $this->lastRecoveryTime = $this->clock->monotonic();

        $this->promoteDelayedJobs();
        $this->lastPromoteTime = $this->clock->monotonic();

        $driverClass = get_class($this->queueManager->driver());
        $this->logger->info('Using queue driver', ['driver' => $driverClass]);

        $this->startTime = $this->clock->monotonic();
        $this->processedJobsCount = 0;
    }

    private function runLoop(): void
    {
        $consecutiveErrors = 0;
        while ($this->shouldRun && !$this->limitsReached()) {
            $this->runDueMaintenance();
            $driver = $this->queueManager->driver();

            try {
                $shouldContinue = $this->runNextIteration($driver);
                $consecutiveErrors = 0;
                if (!$shouldContinue) {
                    return;
                }
            } catch (\Throwable $exception) {
                $consecutiveErrors = $this->loopFailureHandler->handle(
                    $exception,
                    $consecutiveErrors,
                    $this->emit(...)
                );
            }
        }
    }

    private function runNextIteration(QueueDriverInterface $driver): bool
    {
        $claim = $this->claimNextJob($this->options->pollTimeout);
        if ($claim === null) {
            if ($this->options->stopWhenEmpty) {
                $this->logger->info('Queue is empty and stop_when_empty is enabled. Stopping worker.');
                return false;
            }
            return true;
        }

        if (!$this->shouldRun) {
            $this->releaseClaimForShutdown($claim, $driver);
            return false;
        }

        $this->processClaimedJob($claim, $driver);
        return true;
    }

    private function releaseClaimForShutdown(ClaimedJob $claim, QueueDriverInterface $driver): void
    {
        $this->logger->info('Worker shutting down, releasing claimed job', ['job_id' => $claim->job->id]);
        try {
            $this->storage->scheduleRetry($claim, $claim->job->attempts, 0, 'Worker shutting down');
            $driver->nack($this->queue, $claim->job->id, 0);
        } catch (\Throwable $exception) {
            $this->logger->error('Failed to release job during shutdown', [
                'job_id' => $claim->job->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Process a single job (useful for testing or manual processing).
     *
     * @return bool True if a job was processed, false if queue was empty
     */
    public function processOne(): bool
    {
        $driver = $this->queueManager->driver();

        // Promote any delayed jobs that are now due
        if ($driver instanceof SupportsDelayedJobs) {
            $driver->promoteDelayedJobs($this->queue, $this->options->promoteLimit);
        }

        try {
            $claim = $this->claimNextJob(0);
        } catch (\Throwable) {
            return false;
        }

        if ($claim === null) {
            return false;
        }

        $this->processClaimedJob($claim, $driver);
        return true;
    }

    /**
     * Stop the worker gracefully.
     */
    public function stop(): void
    {
        $this->shouldRun = false;
    }

    /**
     * Get the worker ID.
     */
    public function getWorkerId(): string
    {
        return $this->workerId;
    }

    private function claimNextJob(int $timeoutSeconds): ?ClaimedJob
    {
        $startTime = $this->clock->monotonic();
        $driver = $this->queueManager->driver();
        $jobId = null;

        try {
            if ($driver instanceof SupportsClaimedDequeue) {
                $claim = $driver->dequeueClaimed($this->queue, $timeoutSeconds);
                if ($claim === null) {
                    return null;
                }
                return $claim;
            }
            $jobId = $driver->dequeue($this->queue, $timeoutSeconds);

            if ($jobId === null) {
                return null;
            }

            $claim = $this->storage->claimById($jobId, $this->workerId);
        } catch (\Throwable $e) {
            if ($jobId !== null) {
                $this->handlePostPopClaimFailure($driver, $jobId, $e);
            }
            throw $e;
        }

        if ($claim === null) {
            $this->handleUnclaimedJobAck($driver, $jobId);
            return null;
        }

        $latency = ($this->clock->monotonic() - $startTime) * 1000.0;
        $this->emit(new JobClaimedEvent($claim->job->id, $claim->job->type, $latency));

        return $claim;
    }

    private function handlePostPopClaimFailure(
        Contract\QueueDriverInterface $driver,
        int $jobId,
        \Throwable $e
    ): void {
        $this->logger->error('Failed to claim job from storage', [
            'job_id' => $jobId,
            'error' => $e->getMessage(),
        ]);
        try {
            $driver->nack($this->queue, $jobId, 0);
        } catch (\Throwable $nackError) {
            $this->logger->error('Failed to requeue job after claim failure', [
                'job_id' => $jobId,
                'error' => $nackError->getMessage(),
            ]);
        }
    }

    private function handleUnclaimedJobAck(Contract\QueueDriverInterface $driver, int $jobId): void
    {
        $this->logger->warning(
            'Failed to claim job, may have been claimed by another process',
            ['job_id' => $jobId]
        );
        try {
            $driver->ack($this->queue, $jobId);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to ack unclaimed job', ['job_id' => $jobId, 'error' => $e->getMessage()]);
        }
    }

    private function limitsReached(): bool
    {
        if ($this->options->maxJobs > 0 && $this->processedJobsCount >= $this->options->maxJobs) {
            $this->logger->info('Worker limit reached: max_jobs', ['max_jobs' => $this->options->maxJobs]);
            return true;
        }

        if ($this->options->maxTime > 0 && ($this->clock->monotonic() - $this->startTime) >= $this->options->maxTime) {
            $this->logger->info('Worker limit reached: max_time', ['max_time' => $this->options->maxTime]);
            return true;
        }

        if ($this->options->memoryLimit > 0 && memory_get_usage(true) >= $this->options->memoryLimit) {
            $this->logger->info('Worker limit reached: memory_limit', [
                'memory_limit' => $this->options->memoryLimit,
                'current_memory' => memory_get_usage(true)
            ]);
            return true;
        }

        return false;
    }

    private function runDueMaintenance(): void
    {
        $now = $this->clock->monotonic();

        // Promote delayed jobs
        if ($now - $this->lastPromoteTime >= $this->options->promoteInterval) {
            $this->promoteDelayedJobs();
            $this->lastPromoteTime = $now;
        }

        // Recover stale jobs
        if ($now - $this->lastRecoveryTime >= $this->options->recoveryInterval) {
            $this->recoverStaleJobs();
            $this->reconcileDbAndRedis();
            $this->lastRecoveryTime = $now;
        }
    }

    private function promoteDelayedJobs(): void
    {
        $driver = $this->queueManager->driver();
        if ($driver instanceof SupportsDelayedJobs) {
            try {
                $driver->promoteDelayedJobs($this->queue, $this->options->promoteLimit);
            } catch (\Throwable $e) {
                $this->logger->error('Failed to promote delayed jobs', ['error' => $e->getMessage()]);
            }
        }
    }

    private function processClaimedJob(ClaimedJob $claim, QueueDriverInterface $driver): void
    {
        $job = $claim->job;

        $this->logger->info('Processing job', [
            'job_id' => $job->id,
            'type' => $job->type,
            'attempts' => $job->attempts + 1,
        ]);

        $startTime = $this->clock->monotonic();

        try {
            $completed = $this->executeJob($claim);
            $durationMs = ($this->clock->monotonic() - $startTime) * 1000.0;
            $this->handleJobCompletion($claim, $driver, $completed, $durationMs);
        } catch (SerializationException $exception) {
            $durationMs = ($this->clock->monotonic() - $startTime) * 1000.0;
            $this->handleSerializationFailure($claim, $driver, $exception, $durationMs);
        } catch (\Throwable $exception) {
            $durationMs = ($this->clock->monotonic() - $startTime) * 1000.0;
            $this->handleJobFailure($claim, $exception, $driver, $durationMs);
        } finally {
            $this->processedJobsCount++;
        }
    }

    private function handleJobCompletion(
        ClaimedJob $claim,
        QueueDriverInterface $driver,
        bool $completed,
        float $durationMs
    ): void {
        $job = $claim->job;
        if ($this->policy->ownershipOutcome($completed)->isLost()) {
            $this->logger->warning('Lost job ownership before completion ack', ['job_id' => $job->id]);
            $this->emitLostOwnership($claim, 'complete');
            return;
        }

        $this->logger->info('Job completed', [
            'job_id' => $job->id,
            'type' => $job->type,
            'duration_seconds' => round($durationMs / 1000.0, 3),
        ]);
        $this->emit(new JobCompletedEvent($job->id, $job->type, $durationMs));

        try {
            $driver->ack($this->queue, $job->id);
        } catch (\Throwable $exception) {
            $this->logger->error('Failed to ack completed job', [
                'job_id' => $job->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function handleSerializationFailure(
        ClaimedJob $claim,
        QueueDriverInterface $driver,
        SerializationException $exception,
        float $durationMs
    ): void {
        $this->storage->markFailed($claim, $exception->getMessage(), $this->truncateTrace($exception));
        $driver->ack($this->queue, $claim->job->id);
        $this->logger->error('Job result serialization failed after handler completion', [
            'job_id' => $claim->job->id,
            'duration_ms' => $durationMs,
            'error' => $exception->getMessage(),
        ]);
    }

    private function executeJob(ClaimedJob $claim): bool
    {
        $job = $claim->job;

        if (!$this->registry->has($job->type)) {
            throw HandlerNotFoundException::forType($job->type);
        }

        $handler = $this->registry->get($job->type);

        $progressCallback = function (int $percent, ?string $message = null) use ($claim): void {
            $updated = $this->storage->updateProgress($claim, $percent, $message);
            if (!$updated) {
                return;
            }

            $driver = $this->queueManager->driver();
            if (!$driver instanceof SupportsProcessingHeartbeat) {
                return;
            }

            try {
                $driver->heartbeatProcessing($this->queue, $claim->job->id);
            } catch (\Throwable $exception) {
                $this->logger->error('Failed to refresh queue processing visibility', [
                    'job_id' => $claim->job->id,
                    'error' => $exception->getMessage(),
                ]);
                $this->emit(new InfrastructureFailureEvent($claim->job->id, 'processing_heartbeat'));
            }
        };

        $result = JobMiddlewareRunner::run($this->registry->middleware->all(), $claim, $handler, $progressCallback);

        return $this->storage->markCompleted($claim, $result);
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

        try {
            if ($this->policy->retryDecision($attempts, $job->maxAttempts)->shouldRetry()) {
                $this->retryFailedJob($claim, $exception, $driver, $durationMs);
                return;
            }

            $this->failJobPermanently($claim, $exception, $driver, $durationMs);
        } catch (\Throwable $storageError) {
            $this->logger->error('Failed to update job status after failure', [
                'job_id' => $job->id,
                'original_error' => $exception->getMessage(),
                'storage_error' => $storageError->getMessage(),
            ]);
            // Leave job in processing state - will be recovered as stale
        }
    }

    private function retryFailedJob(
        ClaimedJob $claim,
        \Throwable $exception,
        QueueDriverInterface $driver,
        float $durationMs
    ): void {
        $attempts = $claim->job->attempts + 1;
        $delay = $this->policy->retryDelay($attempts);
        $scheduled = $this->scheduleRetry($claim, $delay, $exception);
        if ($this->policy->ownershipOutcome($scheduled)->isLost()) {
            $this->emitLostOwnership($claim, 'retry');
            return;
        }

        $driver->nack($this->queue, $claim->job->id, $delay);
        $this->emit(new JobRetriedEvent(
            $claim->job->id,
            $claim->job->type,
            $durationMs,
            $attempts,
            $exception->getMessage()
        ));
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
        if ($this->policy->ownershipOutcome($marked)->isLost()) {
            $this->logger->warning('Lost job ownership before marking failed', ['job_id' => $claim->job->id]);
            $this->emitLostOwnership($claim, 'fail');
            return;
        }

        $driver->ack($this->queue, $claim->job->id);
        $this->emit(new JobFailedEvent(
            $claim->job->id,
            $claim->job->type,
            $durationMs,
            $exception->getMessage()
        ));
    }

    private function scheduleRetry(ClaimedJob $claim, int $delay, \Throwable $e): bool
    {
        $attempts = $claim->job->attempts + 1;
        $scheduled = $this->storage->scheduleRetry($claim, $attempts, $delay, $e->getMessage());

        if ($scheduled) {
            $this->logger->info('Job scheduled for retry', [
                'job_id' => $claim->job->id,
                'attempts' => $attempts,
                'delay_seconds' => $delay,
            ]);
        } else {
            $this->logger->warning('Lost job ownership before retry scheduling', ['job_id' => $claim->job->id]);
        }

        return $scheduled;
    }

    private function recoverStaleJobs(): void
    {
        $stuckJobTtl = $this->options->stuckJobTtl;
        $recovered = $this->storage instanceof \Oeltima\SimpleQueue\Contract\SupportsQueueScopedStaleRecovery
            ? $this->storage->recoverStaleJobsForQueue($this->queue, $stuckJobTtl, 100)
            : $this->storage->recoverStaleJobs($stuckJobTtl);

        // Also recover from driver if supported
        $driver = $this->queueManager->driver();
        if ($driver instanceof SupportsStaleRecovery) {
            $driverRecovered = $driver->recoverStaleProcessing($this->queue, $stuckJobTtl);
            $recovered += $driverRecovered;
        }

        if ($recovered > 0) {
            $this->logger->warning(
                'Recovered stale jobs',
                ['count' => $recovered, 'ttl_seconds' => $stuckJobTtl]
            );
        }
    }

    /**
     * Reconcile jobs between the database (source of truth) and Redis.
     *
     * Resolves dual-write inconsistencies where a job was committed to DB but enqueuing to Redis failed.
     */
    private function reconcileDbAndRedis(): void
    {
        $driver = $this->queueManager->driver();
        if (!($driver instanceof \Oeltima\SimpleQueue\Contract\SupportsBoundedQueueMembership)) {
            return;
        }

        $storage = $this->storage;
        if (!($storage instanceof \Oeltima\SimpleQueue\Contract\SupportsPendingJobCursor)) {
            return;
        }

        try {
            $result = (new QueueReconciler($storage, $driver, $this->clock))->reconcile(
                $this->queue,
                new ReconcileOptions(cursor: $this->reconcileCursor)
            );
            $this->reconcileCursor = $result->nextCursor;
            $this->logger->info('Bounded DB-Redis reconciliation completed', [
                'scanned' => $result->scanned,
                'restored' => $result->restored,
                'next_cursor' => $result->nextCursor,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to run DB-Redis reconciliation sweep', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function acquireLock(): bool
    {
        if ($this->lockFile === null || PHP_OS_FAMILY === 'Windows') {
            $this->logger->warning('Locking disabled - unsafe for production, dev use only');
            return true;
        }

        $handle = fopen($this->lockFile, 'c');
        if ($handle === false) {
            return false;
        }
        $this->lockHandle = $handle;

        if (!flock($this->lockHandle, LOCK_EX | LOCK_NB)) {
            fclose($this->lockHandle);
            $this->lockHandle = null;
            return false;
        }

        ftruncate($this->lockHandle, 0);
        fwrite($this->lockHandle, $this->workerId);
        fflush($this->lockHandle);

        return true;
    }

    private function releaseLock(): void
    {
        if ($this->lockFile === null || PHP_OS_FAMILY === 'Windows') {
            return;
        }

        if ($this->lockHandle !== null) {
            flock($this->lockHandle, LOCK_UN);
            fclose($this->lockHandle);
            $this->lockHandle = null;
        }
    }

    private function registerSignalHandlers(): void
    {
        if (!function_exists('pcntl_signal')) {
            $this->logger->warning('pcntl extension not available, graceful shutdown may not work');
            return;
        }

        pcntl_async_signals(true);

        $shutdown = function (int $signal): void {
            $signalName = $signal === SIGTERM ? 'SIGTERM' : 'SIGINT';
            $this->logger->info("Received {$signalName}, shutting down after current job...");
            $this->shouldRun = false;
        };

        pcntl_signal(SIGTERM, $shutdown);
        pcntl_signal(SIGINT, $shutdown);
    }

    private function emitLostOwnership(ClaimedJob $claim, string $context): void
    {
        $this->emit(new JobLostOwnershipEvent($claim->job->id, $claim->job->type, $context));
    }

    private function generateWorkerId(): string
    {
        $host = gethostname();
        $hostname = $host === false ? 'unknown' : $host;
        return sprintf('%s:%d', $hostname, getmypid());
    }

    private function truncateTrace(\Throwable $e, int $maxLength = 4000): string
    {
        $trace = $e->getTraceAsString();
        if (strlen($trace) > $maxLength) {
            return substr($trace, 0, $maxLength) . "\n... [truncated]";
        }
        return $trace;
    }
}
