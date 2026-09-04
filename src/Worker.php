<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue;

use Oeltima\SimpleQueue\Contract\ClaimedJob;
use Oeltima\SimpleQueue\Contract\ClockInterface;
use Oeltima\SimpleQueue\Contract\InfrastructureFailureEvent;
use Oeltima\SimpleQueue\Contract\JobClaimedEvent;
use Oeltima\SimpleQueue\Contract\JobCompletedEvent;
use Oeltima\SimpleQueue\Contract\JobFailedEvent;
use Oeltima\SimpleQueue\Contract\JobLostOwnershipEvent;
use Oeltima\SimpleQueue\Contract\JobRetriedEvent;
use Oeltima\SimpleQueue\Contract\JobStorageInterface;
use Oeltima\SimpleQueue\Contract\QueueDriverInterface;
use Oeltima\SimpleQueue\Contract\SleeperInterface;
use Oeltima\SimpleQueue\Contract\SupportsClaimedDequeue;
use Oeltima\SimpleQueue\Contract\SupportsDelayedJobs;
use Oeltima\SimpleQueue\Contract\SupportsProcessingHeartbeat;
use Oeltima\SimpleQueue\Contract\SupportsQueueScopedStaleRecovery;
use Oeltima\SimpleQueue\Contract\SupportsStaleRecovery;
use Oeltima\SimpleQueue\Contract\SupportsTimeoutValidation;
use Oeltima\SimpleQueue\Contract\SupportsWorkerAwareClaimedDequeue;
use Oeltima\SimpleQueue\Contract\SupportsWorkerId;
use Oeltima\SimpleQueue\Exception\HandlerNotFoundException;
use Oeltima\SimpleQueue\Exception\SerializationException;
use Oeltima\SimpleQueue\Internal\JobMiddlewareRunner;
use Oeltima\SimpleQueue\Internal\WorkerEventEmitter;
use Oeltima\SimpleQueue\Internal\WorkerLoopFailureHandler;
use Oeltima\SimpleQueue\Internal\WorkerOwnershipLost;
use Oeltima\SimpleQueue\Internal\WorkerPolicy;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Background worker that processes jobs from the queue.
 *
 * The worker runs in a loop, fetching and processing jobs until
 * it receives a shutdown signal or is manually stopped.
 *
 * Effect ordering per claimed job: persist the fenced durable transition
 * first, emit the matching lifecycle event second, and ACK/NACK third.
 * A false fenced result means ownership was lost and never permits
 * notification cleanup or further handler work.
 */
final class Worker
{
    public const EXIT_SUCCESS = 0;
    public const EXIT_ERROR = 1;
    public const EXIT_LOCK_UNAVAILABLE = 2;

    private readonly LoggerInterface $logger;
    private readonly string $workerId;
    private bool $shouldRun = true;
    /** @var resource|null */
    private $lockHandle = null;
    private readonly ?string $lockFile;

    private readonly WorkerOptions $options;
    private readonly ClockInterface $clock;
    private readonly SleeperInterface $sleeper;
    private readonly WorkerPolicy $policy;
    private readonly WorkerEventEmitter $eventEmitter;
    private readonly WorkerLoopFailureHandler $loopFailureHandler;
    private int $processedJobsCount = 0;
    private float $startTime = 0.0;
    private float $lastPromoteTime = 0.0;
    private float $lastRecoveryTime = 0.0;
    private ?int $reconcileCursor = null;
    private bool $executing = false;
    private bool $shutdownReleaseFailed = false;
    private mixed $priorSigterm = null;
    private mixed $priorSigint = null;
    private ?bool $priorAsyncSignals = null;

    private ?\Closure $eventListener = null;

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
        $this->clock = $this->options->clock ?? new SystemClock();
        $this->sleeper = $this->options->sleeper ?? new SystemSleeper();
        $this->loopFailureHandler = new WorkerLoopFailureHandler($this->logger, $this->policy, $this->sleeper);
        if ($driver instanceof SupportsTimeoutValidation) {
            $driver->validateTimeout($this->options->pollTimeout);
        }
        $this->eventEmitter = new WorkerEventEmitter(
            $this->logger,
            $this->options->eventListener,
            $this->eventListener
        );
    }

    /**
     * @param array<string, mixed>|WorkerOptions $options
     */
    private function resolveLockFile(string $queue, array|WorkerOptions $options): ?string
    {
        $typed = $options instanceof WorkerOptions ? $options : null;
        if ($typed !== null) {
            if (!$typed->lockingEnabled) {
                return null;
            }
            return $typed->lockFile ?? self::defaultLockFile($queue);
        }
        // Array form: explicit null disables, non-empty string is custom, absent is default.
        if (array_key_exists('lock_file', $options)) {
            $raw = $options['lock_file'];
            if ($raw === null) {
                return null;
            }
            if (is_string($raw) && trim($raw) !== '') {
                return $raw;
            }
        }
        if (array_key_exists('locking_enabled', $options) && $options['locking_enabled'] === false) {
            return null;
        }
        if ($this->options->lockFile !== null) {
            return $this->options->lockFile;
        }
        if (!$this->options->lockingEnabled) {
            return null;
        }
        return self::defaultLockFile($queue);
    }

    /**
     * Build the collision-safe default lock path for a queue.
     *
     * @param string $queue Queue name
     * @return string Default lock file path
     */
    private static function defaultLockFile(string $queue): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return sprintf(
                '%s/simplequeue-worker-%s.lock',
                rtrim(sys_get_temp_dir(), '/\\'),
                preg_replace('/[^a-zA-Z0-9_-]/', '', $queue)
            );
        }
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : 0;
        $dir = rtrim(sys_get_temp_dir(), '/') . '/simplequeue-' . $uid;
        $cwdRaw = getcwd();
        $cwd = $cwdRaw !== false ? $cwdRaw : '.';
        $name = hash('sha256', $cwd . "\0" . $queue);
        return $dir . '/worker-' . $name . '.lock';
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
        $this->eventEmitter->setListener($this->eventListener = \Closure::fromCallable($listener));
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
        if ($this->executing) {
            throw new \LogicException('Worker is already executing');
        }
        $this->executing = true;
        // Sequential reuse resets run state.
        $this->shouldRun = true;
        $this->processedJobsCount = 0;
        $this->reconcileCursor = null;
        $this->shutdownReleaseFailed = false;
        $this->logger->info('Worker starting', ['worker_id' => $this->workerId, 'queue' => $this->queue]);

        if (!$this->acquireLock()) {
            $this->executing = false;
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
            $this->restoreSignalHandlers();
            $this->releaseLock();
            $this->executing = false;
        }

        if ($this->shutdownReleaseFailed) {
            $this->logger->error('Worker shutdown release failed; supervisor should react');
            return self::EXIT_ERROR;
        }
        $this->logger->info('Worker stopped gracefully', ['worker_id' => $this->workerId]);
        return self::EXIT_SUCCESS;
    }

    private function initializeRun(): void
    {
        $this->registerSignalHandlers();
        // Delayed promotion runs before reconciliation so a due job still in
        // delayed produces one notification, not two.
        $this->promoteDelayedJobs();
        $this->lastPromoteTime = $this->clock->monotonic();
        $this->recoverStaleJobs();
        $this->reconcileDbAndRedis();
        $this->lastRecoveryTime = $this->clock->monotonic();

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
                    $this->eventEmitter->emit(...)
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
            if (!$this->releaseClaimForShutdown($claim, $driver)) {
                $this->shutdownReleaseFailed = true;
            }
            return false;
        }

        $this->processClaimedJob($claim, $driver);
        return true;
    }

    /**
     * Release a post-signal claim with unchanged attempts.
     *
     * @param ClaimedJob $claim Claimed job to release
     * @param QueueDriverInterface $driver Queue driver
     * @return bool True when durable release (and NACK) succeeded
     */
    private function releaseClaimForShutdown(ClaimedJob $claim, QueueDriverInterface $driver): bool
    {
        $this->logger->info('Worker shutting down, releasing claimed job', ['job_id' => $claim->job->id]);
        try {
            $released = $this->storage->scheduleRetry($claim, $claim->job->attempts, 0, 'Worker shutting down');
        } catch (\Throwable $exception) {
            $this->logger->error('Failed to release job during shutdown', [
                'job_id' => $claim->job->id,
                'error' => $exception->getMessage(),
            ]);
            return false;
        }
        if (!$released) {
            $this->emitLostOwnershipEvent($claim, 'shutdown_release');
            return true;
        }
        try {
            $driver->nack($this->queue, $claim->job->id, 0);
        } catch (\Throwable $exception) {
            $this->logger->error('Failed to nack released job during shutdown', [
                'job_id' => $claim->job->id,
                'error' => $exception->getMessage(),
            ]);
            return false;
        }
        return true;
    }

    /**
     * Process a single job (useful for testing or manual processing).
     *
     * Returns false only when no claim is available. Promotion, claim,
     * storage, and notifier errors are thrown to the caller.
     *
     * @return bool True if a job was processed, false if queue was empty
     */
    public function processOne(): bool
    {
        if ($this->executing) {
            throw new \LogicException('Worker is already executing');
        }
        $this->executing = true;
        try {
            $driver = $this->queueManager->driver();

            // Promote any delayed jobs that are now due
            if ($driver instanceof SupportsDelayedJobs) {
                $driver->promoteDelayedJobs($this->queue, $this->options->promoteLimit);
            }

            $claim = $this->claimNextJob(0);

            if ($claim === null) {
                return false;
            }

            $this->processClaimedJob($claim, $driver);
            return true;
        } finally {
            $this->executing = false;
        }
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
            if ($driver instanceof SupportsWorkerAwareClaimedDequeue) {
                $claim = $driver->dequeueClaimedForWorker($this->queue, $timeoutSeconds, $this->workerId);
                if ($claim === null) {
                    return null;
                }
                $this->emitClaimedEvent($claim, ($this->clock->monotonic() - $startTime) * 1000.0);
                return $claim;
            }
            if ($driver instanceof SupportsClaimedDequeue) {
                $claim = $driver->dequeueClaimed($this->queue, $timeoutSeconds);
                if ($claim === null) {
                    return null;
                }
                $this->emitClaimedEvent($claim, ($this->clock->monotonic() - $startTime) * 1000.0);
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
        $this->emitClaimedEvent($claim, $latency);

        return $claim;
    }

    /**
     * Emit a claimed event without constructing its payload on the hot path.
     *
     * @param ClaimedJob $claim Claimed job
     * @param float $latency Claim acquisition latency in milliseconds
     */
    private function emitClaimedEvent(ClaimedJob $claim, float $latency): void
    {
        if (!$this->eventEmitter->isListening()) {
            return;
        }

        $this->eventEmitter->emit(new JobClaimedEvent($claim->job->id, $claim->job->type, $latency));
    }

    /**
     * Emit a completion event without constructing its payload on the hot path.
     *
     * @param ClaimedJob $claim Completed job
     * @param float $durationMs Handler duration in milliseconds
     */
    private function emitCompletedEvent(ClaimedJob $claim, float $durationMs): void
    {
        if (!$this->eventEmitter->isListening()) {
            return;
        }

        $this->eventEmitter->emit(new JobCompletedEvent($claim->job->id, $claim->job->type, $durationMs));
    }

    /**
     * Emit an infrastructure failure event without constructing its payload on the hot path.
     *
     * @param ClaimedJob $claim Affected job
     */
    private function emitInfrastructureFailureEvent(ClaimedJob $claim): void
    {
        if (!$this->eventEmitter->isListening()) {
            return;
        }

        $this->eventEmitter->emit(new InfrastructureFailureEvent($claim->job->id, 'processing_heartbeat'));
    }

    /**
     * Emit a retry event without constructing its payload on the hot path.
     *
     * @param ClaimedJob $claim Retried job
     * @param float $durationMs Handler duration in milliseconds
     * @param \Throwable $exception Failure being retried
     */
    private function emitRetriedEvent(
        ClaimedJob $claim,
        float $durationMs,
        \Throwable $exception
    ): void {
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

    /**
     * Emit a permanent-failure event without constructing its payload on the hot path.
     *
     * @param ClaimedJob $claim Failed job
     * @param float $durationMs Handler duration in milliseconds
     * @param \Throwable $exception Failure that exhausted retries
     */
    private function emitFailedEvent(ClaimedJob $claim, float $durationMs, \Throwable $exception): void
    {
        if (!$this->eventEmitter->isListening()) {
            return;
        }

        $this->eventEmitter->emit(new JobFailedEvent(
            $claim->job->id,
            $claim->job->type,
            $durationMs,
            $exception->getMessage()
        ));
    }

    /**
     * Emit a lost-ownership event without constructing its payload on the hot path.
     *
     * @param ClaimedJob $claim Job that lost ownership
     * @param string $context Lifecycle operation that lost ownership
     */
    private function emitLostOwnershipEvent(ClaimedJob $claim, string $context): void
    {
        if (!$this->eventEmitter->isListening()) {
            return;
        }

        $this->eventEmitter->emit(new JobLostOwnershipEvent($claim->job->id, $claim->job->type, $context));
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

        if ($this->options->memoryLimit > 0) {
            $limitBytes = $this->memoryLimitBytes();
            if ($limitBytes !== null && memory_get_usage(true) >= $limitBytes) {
                $this->logger->info('Worker limit reached: memory_limit', [
                    'memory_limit' => $this->options->memoryLimit,
                    'current_memory' => memory_get_usage(true),
                ]);
                return true;
            }
        }

        return false;
    }

    /**
     * Interpret memoryLimit as MiB with overflow-safe byte conversion.
     *
     * @return int|null Byte limit or null when disabled
     */
    private function memoryLimitBytes(): ?int
    {
        if ($this->options->memoryLimit <= 0) {
            return null;
        }
        $mib = $this->options->memoryLimit;
        if ($mib > intdiv(PHP_INT_MAX, 1048576)) {
            return PHP_INT_MAX;
        }
        return $mib * 1048576;
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
        $handlerCounted = false;

        try {
            // Handler/middleware exceptions are job failures even for PDO/Redis types.
            try {
                $result = $this->executeHandler($claim);
            } catch (WorkerOwnershipLost $lost) {
                // Progress ownership loss already emitted; handler had begun so count it.
                $this->processedJobsCount++;
                return;
            } catch (\Throwable $handlerException) {
                $durationMs = ($this->clock->monotonic() - $startTime) * 1000.0;
                $this->processedJobsCount++;
                $handlerCounted = true;
                $this->handleJobFailure($claim, $handlerException, $driver, $durationMs);
                return;
            }
            $durationMs = ($this->clock->monotonic() - $startTime) * 1000.0;
            $this->processedJobsCount++;
            $handlerCounted = true;
            // Durable transition errors escape as infrastructure (never handler failures).
            try {
                $completed = $this->storage->markCompleted($claim, $result);
            } catch (SerializationException $exception) {
                $this->handleResultSerializationFailure($claim, $driver, $exception, $durationMs);
                return;
            }
            $this->handleJobCompletion($claim, $driver, $completed, $durationMs);
        } finally {
            if (!$handlerCounted) {
                // Handler never began (e.g. ownership lost before execution).
                // max_jobs counts only started handler attempts.
            }
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
            $this->emitLostOwnershipEvent($claim, 'complete');
            return;
        }

        $this->logger->info('Job completed', [
            'job_id' => $job->id,
            'type' => $job->type,
            'duration_seconds' => round($durationMs / 1000.0, 3),
        ]);
        // Persisted first, emit second, ACK third.
        $this->emitCompletedEvent($claim, $durationMs);

        try {
            $driver->ack($this->queue, $job->id);
        } catch (\Throwable $exception) {
            $this->logger->error('Failed to ack completed job', [
                'job_id' => $job->id,
                'operation' => 'ack',
                'error' => $exception->getMessage(),
            ]);
            throw $exception;
        }
    }

    /**
     * Handle result-serialization failure as an immediate fenced terminal failure.
     *
     * @param ClaimedJob $claim Claimed job
     * @param QueueDriverInterface $driver Queue driver
     * @param SerializationException $exception Serialization failure
     * @param float $durationMs Handler duration in milliseconds
     */
    private function handleResultSerializationFailure(
        ClaimedJob $claim,
        QueueDriverInterface $driver,
        SerializationException $exception,
        float $durationMs
    ): void {
        // Never rerun the handler merely to recreate its result.
        $marked = $this->storage->markFailed($claim, $exception->getMessage(), $this->truncateTrace($exception));
        if ($this->policy->ownershipOutcome($marked)->isLost()) {
            $this->emitLostOwnershipEvent($claim, 'result_serialization');
            return;
        }
        $this->logger->error('Job result serialization failed after handler completion', [
            'job_id' => $claim->job->id,
            'duration_ms' => $durationMs,
            'error' => $exception->getMessage(),
        ]);
        $this->emitFailedEvent($claim, $durationMs, $exception);
        try {
            $driver->ack($this->queue, $claim->job->id);
        } catch (\Throwable $ackException) {
            $this->logger->error('Failed to ack serialization-failed job', [
                'job_id' => $claim->job->id,
                'operation' => 'ack',
                'error' => $ackException->getMessage(),
            ]);
            throw $ackException;
        }
    }

    /**
     * Execute middleware/handler outside the durable completion call.
     *
     * @param ClaimedJob $claim Claimed job
     * @return mixed Handler result
     */
    private function executeHandler(ClaimedJob $claim): mixed
    {
        $job = $claim->job;

        if (!$this->registry->has($job->type)) {
            throw HandlerNotFoundException::forType($job->type);
        }

        $handler = $this->registry->get($job->type);

        $progressCallback = function (int $percent, ?string $message = null) use ($claim): void {
            try {
                $updated = $this->storage->updateProgress($claim, $percent, $message);
            } catch (\Throwable $exception) {
                // Progress storage exception leaves the claim running; infra failure escapes.
                throw $exception;
            }
            if (!$updated) {
                $this->emitLostOwnershipEvent($claim, 'progress');
                throw new WorkerOwnershipLost('Lost job ownership during progress update');
            }

            $driver = $this->queueManager->driver();
            if (!$driver instanceof SupportsProcessingHeartbeat) {
                return;
            }

            try {
                $driver->heartbeatProcessing($this->queue, $claim->job->id);
            } catch (\Throwable $exception) {
                // Queue heartbeat failure is non-fatal after durable heartbeat; fencing still protects completion.
                $this->logger->error('Failed to refresh queue processing visibility', [
                    'job_id' => $claim->job->id,
                    'error' => $exception->getMessage(),
                ]);
                $this->emitInfrastructureFailureEvent($claim);
            }
        };

        return JobMiddlewareRunner::run($this->registry->middleware->all(), $claim, $handler, $progressCallback);
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
        $scheduled = $this->scheduleRetry($claim, $delay, $exception);
        if ($this->policy->ownershipOutcome($scheduled)->isLost()) {
            $this->emitLostOwnershipEvent($claim, 'retry');
            return;
        }

        // Emit second, NACK third; NACK failure preserves the event and escapes as infra.
        $this->emitRetriedEvent($claim, $durationMs, $exception);
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
        if ($this->policy->ownershipOutcome($marked)->isLost()) {
            $this->logger->warning('Lost job ownership before marking failed', ['job_id' => $claim->job->id]);
            $this->emitLostOwnershipEvent($claim, 'fail');
            return;
        }

        $this->emitFailedEvent($claim, $durationMs, $exception);
        try {
            $driver->ack($this->queue, $claim->job->id);
        } catch (\Throwable $ackException) {
            $this->logger->error('Failed to ack failed job', [
                'job_id' => $claim->job->id,
                'operation' => 'ack',
                'error' => $ackException->getMessage(),
            ]);
            throw $ackException;
        }
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
        $recovered = $this->storage instanceof SupportsQueueScopedStaleRecovery
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
        if ($this->lockFile === null) {
            $this->logger->warning('Locking disabled - unsafe for production, dev use only');
            return true;
        }
        if (PHP_OS_FAMILY === 'Windows') {
            $this->logger->warning('Locking disabled - unsafe for production, dev use only');
            return true;
        }

        $dir = dirname($this->lockFile);
        if (!is_dir($dir)) {
            $oldUmask = umask(0077);
            try {
                if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
                    return false;
                }
            } finally {
                umask($oldUmask);
            }
        }
        // Require a real current-user-owned directory with mode 0700.
        $realDir = realpath($dir);
        if ($realDir === false || !is_dir($realDir)) {
            return false;
        }
        if (function_exists('posix_geteuid')) {
            $owner = @fileowner($realDir);
            if ($owner !== posix_geteuid()) {
                $this->logger->error('Lock directory is not owned by the current user');
                return false;
            }
            $perms = @fileperms($realDir);
            if ($perms !== false && ($perms & 0777) !== 0700) {
                @chmod($realDir, 0700);
            }
        }
        // Reject symlink/non-regular lock targets.
        if (file_exists($this->lockFile) || is_link($this->lockFile)) {
            if (is_link($this->lockFile) || (file_exists($this->lockFile) && !is_file($this->lockFile))) {
                $this->logger->error('Lock path is not a regular file');
                return false;
            }
        }
        $handle = fopen($this->lockFile, 'c');
        if ($handle === false) {
            return false;
        }
        // Create with 0600 regardless of umask.
        @chmod($this->lockFile, 0600);
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

        $this->priorAsyncSignals = pcntl_async_signals();
        if (function_exists('pcntl_signal_get_handler')) {
            $this->priorSigterm = pcntl_signal_get_handler(SIGTERM);
            $this->priorSigint = pcntl_signal_get_handler(SIGINT);
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

    private function restoreSignalHandlers(): void
    {
        if (!function_exists('pcntl_signal')) {
            return;
        }
        try {
            if (function_exists('pcntl_signal_get_handler')) {
                if ($this->priorSigterm !== null && (is_callable($this->priorSigterm) || is_int($this->priorSigterm))) {
                    pcntl_signal(SIGTERM, $this->priorSigterm);
                }
                if ($this->priorSigint !== null && (is_callable($this->priorSigint) || is_int($this->priorSigint))) {
                    pcntl_signal(SIGINT, $this->priorSigint);
                }
            }
            if ($this->priorAsyncSignals !== null) {
                pcntl_async_signals($this->priorAsyncSignals);
            }
        } catch (\Throwable) {
            // Best-effort restore; never mask the run outcome.
        } finally {
            $this->priorSigterm = null;
            $this->priorSigint = null;
            $this->priorAsyncSignals = null;
        }
    }

    private function generateWorkerId(): string
    {
        $host = gethostname();
        $hostname = $host === false ? 'unknown' : $host;
        $hostname = substr($hostname, 0, 200);
        $random = bin2hex(random_bytes(8));
        $id = sprintf('%s:%d:%s', $hostname, getmypid(), $random);
        // Fit the 255-byte storage limit.
        if (strlen($id) > 255) {
            $id = substr($id, 0, 255);
        }
        return $id;
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
