<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue;

use Oeltima\SimpleQueue\Contract\ClockInterface;
use Oeltima\SimpleQueue\Contract\ClaimedJob;
use Oeltima\SimpleQueue\Contract\JobStorageInterface;
use Oeltima\SimpleQueue\Contract\QueueDriverInterface;
use Oeltima\SimpleQueue\Exception\HandlerNotFoundException;
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

    private const DEFAULT_POLL_TIMEOUT = 5;
    private const DEFAULT_STUCK_JOB_TTL = 600; // 10 minutes

    private JobStorageInterface $storage;
    private QueueManager $queueManager;
    private JobRegistry $registry;
    private LoggerInterface $logger;
    private string $workerId;
    private string $queue;
    private bool $shouldRun = true;
    private mixed $lockHandle = null;
    private ?string $lockFile;

    private int $pollTimeout;
    private int $stuckJobTtl;
    private int $retryBaseDelay;
    private int $retryMaxDelay;
    private ClockInterface $clock;

    /**
     * @param JobStorageInterface $storage Job storage implementation
     * @param QueueManager $queueManager Queue manager instance
     * @param JobRegistry $registry Job handler registry
     * @param LoggerInterface|null $logger PSR-3 logger (optional)
     * @param string $queue Queue name to process
     * @param array<string, mixed> $options Worker options
     */
    public function __construct(
        JobStorageInterface $storage,
        QueueManager $queueManager,
        JobRegistry $registry,
        ?LoggerInterface $logger = null,
        string $queue = 'default',
        array $options = []
    ) {
        $this->storage = $storage;
        $this->queueManager = $queueManager;
        $this->registry = $registry;
        $this->logger = $logger ?? new NullLogger();
        $this->queue = $queue;
        $this->workerId = $this->generateWorkerId();

        $driver = $this->queueManager->driver();
        if (method_exists($driver, 'setWorkerId')) {
            $driver->setWorkerId($this->workerId);
        }

        // Configuration options
        $this->lockFile = $options['lock_file'] ?? '/tmp/simplequeue-worker.lock';
        $this->pollTimeout = (int) ($options['poll_timeout'] ?? self::DEFAULT_POLL_TIMEOUT);
        $this->stuckJobTtl = (int) ($options['stuck_job_ttl'] ?? self::DEFAULT_STUCK_JOB_TTL);
        $this->retryBaseDelay = (int) ($options['retry_base_delay'] ?? 2);
        $this->retryMaxDelay = (int) ($options['retry_max_delay'] ?? 300);
        $clock = $options['clock'] ?? null;
        $this->clock = $clock instanceof ClockInterface ? $clock : new SystemClock();
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
            $this->recoverStaleJobs();

            $driverClass = get_class($this->queueManager->driver());
            $this->logger->info('Using queue driver', ['driver' => $driverClass]);

            $consecutiveErrors = 0;

            while ($this->shouldRun) {
                // Promote any delayed jobs that are now due
                $driver = $this->queueManager->driver();
                if (method_exists($driver, 'promoteDelayedJobs')) {
                    $driver->promoteDelayedJobs($this->queue);
                }

                try {
                    $claim = $this->claimNextJob($this->pollTimeout);

                    if ($claim === null) {
                        $consecutiveErrors = 0;
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
        if (method_exists($driver, 'promoteDelayedJobs')) {
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
        $driver = $this->queueManager->driver();
        $jobId = null;

        try {
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
            $duration = round($this->clock->monotonic() - $startTime, 3);

            if (!$completed) {
                $this->logger->warning('Lost job ownership before completion ack', ['job_id' => $job->id]);
                return;
            }

            $this->logger->info('Job completed', [
                'job_id' => $job->id,
                'type' => $job->type,
                'duration_seconds' => $duration,
            ]);

            try {
                $driver->ack($this->queue, $job->id);
            } catch (\Throwable $e) {
                $this->logger->error('Failed to ack completed job', [
                    'job_id' => $job->id,
                    'error' => $e->getMessage(),
                ]);
            }
        } catch (\Throwable $e) {
            $duration = round($this->clock->monotonic() - $startTime, 3);
            $this->handleJobFailure($claim, $e, $driver, $duration);
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
            $this->storage->updateProgress($claim, $percent, $message);
        };

        $result = $handler->handle($job->id, $job->payload, $progressCallback);

        return $this->storage->markCompleted($claim, $result);
    }

    private function handleJobFailure(
        ClaimedJob $claim,
        \Throwable $e,
        QueueDriverInterface $driver,
        float $duration
    ): void {
        $job = $claim->job;
        $attempts = $job->attempts + 1;

        $this->logger->error('Job failed', [
            'job_id' => $job->id,
            'type' => $job->type,
            'attempts' => $attempts,
            'max_attempts' => $job->maxAttempts,
            'duration_seconds' => $duration,
            'error' => $e->getMessage(),
        ]);

        try {
            if ($attempts < $job->maxAttempts) {
                $delay = $this->calculateRetryDelay($attempts);
                if ($this->scheduleRetry($claim, $attempts, $delay, $e)) {
                    $driver->nack($this->queue, $job->id, $delay);
                }
            } else {
                if ($this->storage->markFailed($claim, $e->getMessage(), $this->truncateTrace($e))) {
                    $driver->ack($this->queue, $job->id);
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
        $recovered = $this->storage->recoverStaleJobs($this->stuckJobTtl);

        // Also recover from driver if supported
        $driver = $this->queueManager->driver();
        if (method_exists($driver, 'recoverStaleProcessing')) {
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

    private function acquireLock(): bool
    {
        if ($this->lockFile === null || PHP_OS_FAMILY === 'Windows') {
            $this->logger->warning('Locking disabled - unsafe for production, dev use only');
            return true;
        }

        $this->lockHandle = fopen($this->lockFile, 'c');
        if ($this->lockHandle === false) {
            return false;
        }

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
        $hostname = gethostname() ?: 'unknown';
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
