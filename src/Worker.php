<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue;

use Oeltima\SimpleQueue\Contract\ClockInterface;
use Oeltima\SimpleQueue\Contract\ClaimedJob;
use Oeltima\SimpleQueue\Contract\JobStorageInterface;
use Oeltima\SimpleQueue\Contract\QueueDriverInterface;
use Oeltima\SimpleQueue\Contract\SupportsDelayedJobs;
use Oeltima\SimpleQueue\Contract\SupportsStaleRecovery;
use Oeltima\SimpleQueue\Contract\SupportsProcessingHeartbeat;
use Oeltima\SimpleQueue\Contract\SupportsWorkerId;
use Oeltima\SimpleQueue\Contract\SupportsTimeoutValidation;
use Oeltima\SimpleQueue\Contract\SupportsClaimedDequeue;
use Oeltima\SimpleQueue\Exception\HandlerNotFoundException;
use Oeltima\SimpleQueue\Exception\SerializationException;
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

    private int $pollTimeout;
    private int $stuckJobTtl;
    private int $retryBaseDelay;
    private int $retryMaxDelay;
    private ClockInterface $clock;

    private int $maxJobs;
    private int $maxTime;
    private int $memoryLimit;
    private bool $stopWhenEmpty;
    private int $processedJobsCount = 0;
    private float $startTime = 0.0;

    private float $promoteInterval;
    private float $recoveryInterval;
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
     * @param array<string, mixed> $options Worker options
     */
    public function __construct(
        private readonly JobStorageInterface $storage,
        private readonly QueueManager $queueManager,
        private readonly JobRegistry $registry,
        ?LoggerInterface $logger = null,
        private readonly string $queue = 'default',
        array $options = []
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->workerId = $this->generateWorkerId();

        $driver = $this->queueManager->driver();
        if ($driver instanceof SupportsWorkerId) {
            $driver->setWorkerId($this->workerId);
        }

        $workerOptions = WorkerOptions::fromArray($options);
        $lockFileOpt = $workerOptions->lockFile;
        if (array_key_exists('lock_file', $options)) {
            $this->lockFile = is_string($lockFileOpt) ? $lockFileOpt : null;
        } else {
            $this->lockFile = sprintf('/tmp/simplequeue-worker-%s.lock', preg_replace('/[^a-zA-Z0-9_-]/', '', $queue));
        }
        $this->pollTimeout = $workerOptions->pollTimeout;
        $this->stuckJobTtl = $workerOptions->stuckJobTtl;
        $this->retryBaseDelay = $workerOptions->retryBaseDelay;
        $this->retryMaxDelay = $workerOptions->retryMaxDelay;
        $this->clock = $workerOptions->clock ?? new SystemClock();
        $this->maxJobs = $workerOptions->maxJobs;
        $this->maxTime = $workerOptions->maxTime;
        $this->memoryLimit = $workerOptions->memoryLimit;
        $this->stopWhenEmpty = $workerOptions->stopWhenEmpty;
        $this->promoteInterval = $workerOptions->promoteInterval;
        $this->recoveryInterval = $workerOptions->recoveryInterval;

        if ($driver instanceof SupportsTimeoutValidation) {
            $driver->validateTimeout($this->pollTimeout);
        }

        if (is_callable($workerOptions->eventListener)) {
            $this->eventListener = $workerOptions->eventListener;
        }
    }

    public static function withOptions(
        JobStorageInterface $storage,
        QueueManager $queueManager,
        JobRegistry $registry,
        WorkerOptions $options,
        ?LoggerInterface $logger = null,
        string $queue = 'default'
    ): self {
        return new self($storage, $queueManager, $registry, $logger, $queue, [
            'lock_file' => $options->lockFile,
            'poll_timeout' => $options->pollTimeout,
            'stuck_job_ttl' => $options->stuckJobTtl,
            'retry_base_delay' => $options->retryBaseDelay,
            'retry_max_delay' => $options->retryMaxDelay,
            'clock' => $options->clock,
            'max_jobs' => $options->maxJobs,
            'max_time' => $options->maxTime,
            'memory_limit' => $options->memoryLimit,
            'stop_when_empty' => $options->stopWhenEmpty,
            'promote_interval' => $options->promoteInterval,
            'recovery_interval' => $options->recoveryInterval,
            'event_listener' => $options->eventListener,
        ]);
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
     * Emit an event to the registered event listener.
     *
     * @param string $event Event name
     * @param array<string, mixed> $data Event data
     */
    private function emit(string $event, array $data): void
    {
        if ($this->eventListener !== null) {
            try {
                ($this->eventListener)($event, $data);
            } catch (\Throwable $listenerError) {
                $this->logger->error('Worker event listener threw an exception', [
                    'event' => $event,
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
            $this->registerSignalHandlers();

            // Run initial recovery and promotion immediately
            $this->recoverStaleJobs();
            $this->reconcileDbAndRedis();
            $this->lastRecoveryTime = $this->clock->monotonic();

            $this->promoteDelayedJobs();
            $this->lastPromoteTime = $this->clock->monotonic();

            $driverClass = get_class($this->queueManager->driver());
            $this->logger->info('Using queue driver', ['driver' => $driverClass]);

            $this->startTime = $this->clock->monotonic();
            $this->processedJobsCount = 0;
            $consecutiveErrors = 0;

            while ($this->shouldRun) {
                if ($this->limitsReached()) {
                    break;
                }

                $this->runDueMaintenance();

                $driver = $this->queueManager->driver();
                try {
                    $claim = $this->claimNextJob($this->pollTimeout);

                    if ($claim === null) {
                        $consecutiveErrors = 0;
                        if ($this->stopWhenEmpty) {
                            $this->logger->info('Queue is empty and stop_when_empty is enabled. Stopping worker.');
                            break;
                        }
                        continue;
                    }

                    // @phpstan-ignore-next-line
                    if (!$this->shouldRun) {
                        $this->logger->info(
                            'Worker shutting down, releasing claimed job',
                            ['job_id' => $claim->job->id]
                        );
                        try {
                            $this->storage->scheduleRetry($claim, $claim->job->attempts, 0, 'Worker shutting down');
                            $driver->nack($this->queue, $claim->job->id, 0);
                        } catch (\Throwable $releaseError) {
                            $this->logger->error('Failed to release job during shutdown', [
                                'job_id' => $claim->job->id,
                                'error' => $releaseError->getMessage()
                            ]);
                        }
                        break;
                    }

                    $this->processClaimedJob($claim, $driver);
                    $consecutiveErrors = 0;
                } catch (\Throwable $e) {
                    if ($this->isInfrastructureException($e)) {
                        $consecutiveErrors++;
                        $delay = $this->calculateBackoff($consecutiveErrors);
                        $jitterMs = random_int(0, 1000);
                        $totalDelaySeconds = $delay + ($jitterMs / 1000.0);

                        $this->logger->error('Infrastructure error encountered. Backing off.', [
                            'error' => $e->getMessage(),
                            'backoff_seconds' => round($totalDelaySeconds, 3),
                            'consecutive_errors' => $consecutiveErrors,
                        ]);

                        $this->emit('infra_error', [
                            'error' => $e->getMessage(),
                            'exception' => $e,
                        ]);

                        $this->emit('backoff', [
                            'error' => $e->getMessage(),
                            'backoff_seconds' => $totalDelaySeconds,
                        ]);

                        $this->sleep($totalDelaySeconds);
                    } else {
                        $this->logger->error('Worker loop encountered an unexpected error', [
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString(),
                        ]);
                        $this->sleep(1.0);
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->logger->critical('Worker encountered a fatal error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return self::EXIT_ERROR;
        } finally {
            $this->releaseLock();
        }

        $this->logger->info('Worker stopped gracefully', ['worker_id' => $this->workerId]);
        return self::EXIT_SUCCESS;
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
            $driver->promoteDelayedJobs($this->queue);
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
            // Requeue on post-pop claim failure
            if ($jobId !== null) {
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

            throw $e;
        }

        if ($claim === null) {
            $this->logger->warning(
                'Failed to claim job, may have been claimed by another process',
                ['job_id' => $jobId]
            );
            try {
                $driver->ack($this->queue, $jobId);
            } catch (\Throwable $e) {
                $this->logger->error('Failed to ack unclaimed job', ['job_id' => $jobId, 'error' => $e->getMessage()]);
            }
            return null;
        }

        $latency = ($this->clock->monotonic() - $startTime) * 1000.0;
        $this->emit('claimed', [
            'job_id' => $claim->job->id,
            'type' => $claim->job->type,
            'acquire_latency_ms' => $latency,
        ]);

        return $claim;
    }

    private function isInfrastructureException(\Throwable $e): bool
    {
        if ($e instanceof \PDOException || $e instanceof \RedisException) {
            return true;
        }
        $class = get_class($e);
        if (str_starts_with($class, 'Predis\\')) {
            return true;
        }
        return false;
    }

    private function calculateBackoff(int $errorCount): int
    {
        return min($this->retryMaxDelay, (int) pow($this->retryBaseDelay, $errorCount));
    }

    private function sleep(float $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }
        usleep((int) ($seconds * 1_000_000));
    }

    private function limitsReached(): bool
    {
        if ($this->maxJobs > 0 && $this->processedJobsCount >= $this->maxJobs) {
            $this->logger->info('Worker limit reached: max_jobs', ['max_jobs' => $this->maxJobs]);
            return true;
        }

        if ($this->maxTime > 0 && ($this->clock->monotonic() - $this->startTime) >= $this->maxTime) {
            $this->logger->info('Worker limit reached: max_time', ['max_time' => $this->maxTime]);
            return true;
        }

        if ($this->memoryLimit > 0 && memory_get_usage(true) >= $this->memoryLimit) {
            $this->logger->info('Worker limit reached: memory_limit', [
                'memory_limit' => $this->memoryLimit,
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
        if ($now - $this->lastPromoteTime >= $this->promoteInterval) {
            $this->promoteDelayedJobs();
            $this->lastPromoteTime = $now;
        }

        // Recover stale jobs
        if ($now - $this->lastRecoveryTime >= $this->recoveryInterval) {
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
                $driver->promoteDelayedJobs($this->queue);
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

            if (!$completed) {
                $this->logger->warning('Lost job ownership before completion ack', ['job_id' => $job->id]);
                $this->emit('lost_ownership', [
                    'job_id' => $job->id,
                    'type' => $job->type,
                    'context' => 'complete',
                ]);
                return;
            }

            $this->logger->info('Job completed', [
                'job_id' => $job->id,
                'type' => $job->type,
                'duration_seconds' => round($durationMs / 1000.0, 3),
            ]);

            $this->emit('completed', [
                'job_id' => $job->id,
                'type' => $job->type,
                'duration_ms' => $durationMs,
            ]);

            try {
                $driver->ack($this->queue, $job->id);
            } catch (\Throwable $e) {
                $this->logger->error('Failed to ack completed job', [
                    'job_id' => $job->id,
                    'error' => $e->getMessage(),
                ]);
            }
        } catch (SerializationException $e) {
            $durationMs = ($this->clock->monotonic() - $startTime) * 1000.0;
            $this->storage->markFailed($claim, $e->getMessage(), $this->truncateTrace($e));
            $driver->ack($this->queue, $job->id);
            $this->logger->error('Job result serialization failed after handler completion', [
                'job_id' => $job->id,
                'duration_ms' => $durationMs,
                'error' => $e->getMessage(),
            ]);
        } catch (\Throwable $e) {
            $durationMs = ($this->clock->monotonic() - $startTime) * 1000.0;
            $this->handleJobFailure($claim, $e, $driver, $durationMs);
        } finally {
            $this->processedJobsCount++;
        }
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
                $this->emit('infrastructure_failure', [
                    'job_id' => $claim->job->id,
                    'context' => 'processing_heartbeat',
                ]);
            }
        };

        $result = $handler->handle($job->id, $job->payload, $progressCallback);

        return $this->storage->markCompleted($claim, $result);
    }

    private function handleJobFailure(
        ClaimedJob $claim,
        \Throwable $e,
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
            'error' => $e->getMessage(),
        ]);

        try {
            if ($attempts < $job->maxAttempts) {
                $delay = $this->calculateRetryDelay($attempts);
                if ($this->scheduleRetry($claim, $attempts, $delay, $e)) {
                    $driver->nack($this->queue, $job->id, $delay);
                    $this->emit('retried', [
                        'job_id' => $job->id,
                        'type' => $job->type,
                        'duration_ms' => $durationMs,
                        'attempts' => $attempts,
                        'error' => $e->getMessage(),
                    ]);
                } else {
                    $this->emit('lost_ownership', [
                        'job_id' => $job->id,
                        'type' => $job->type,
                        'context' => 'retry',
                    ]);
                }
            } else {
                $marked = $this->storage->markFailed($claim, $e->getMessage(), $this->truncateTrace($e));
                if ($marked) {
                    $driver->ack($this->queue, $job->id);
                    $this->emit('failed', [
                        'job_id' => $job->id,
                        'type' => $job->type,
                        'duration_ms' => $durationMs,
                        'error' => $e->getMessage(),
                    ]);
                } else {
                    $this->logger->warning('Lost job ownership before marking failed', ['job_id' => $job->id]);
                    $this->emit('lost_ownership', [
                        'job_id' => $job->id,
                        'type' => $job->type,
                        'context' => 'fail',
                    ]);
                }
            }
        } catch (\Throwable $storageError) {
            $this->logger->error('Failed to update job status after failure', [
                'job_id' => $job->id,
                'original_error' => $e->getMessage(),
                'storage_error' => $storageError->getMessage(),
            ]);
            // Leave job in processing state - will be recovered as stale
        }
    }

    private function scheduleRetry(ClaimedJob $claim, int $attempts, int $delay, \Throwable $e): bool
    {
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

    private function calculateRetryDelay(int $attempts): int
    {
        return min($this->retryMaxDelay, (int) pow($this->retryBaseDelay, $attempts));
    }

    private function recoverStaleJobs(): void
    {
        $recovered = $this->storage instanceof \Oeltima\SimpleQueue\Contract\SupportsQueueScopedStaleRecovery
            ? $this->storage->recoverStaleJobsForQueue($this->queue, $this->stuckJobTtl, 100)
            : $this->storage->recoverStaleJobs($this->stuckJobTtl);

        // Also recover from driver if supported
        $driver = $this->queueManager->driver();
        if ($driver instanceof SupportsStaleRecovery) {
            $driverRecovered = $driver->recoverStaleProcessing($this->queue, $this->stuckJobTtl);
            $recovered += $driverRecovered;
        }

        if ($recovered > 0) {
            $this->logger->warning(
                'Recovered stale jobs',
                ['count' => $recovered, 'ttl_seconds' => $this->stuckJobTtl]
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
                'trace' => $e->getTraceAsString(),
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
