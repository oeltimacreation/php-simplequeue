<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue;

use Oeltima\SimpleQueue\Contract\JobData;
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

        // Configuration options
        $this->lockFile = $options['lock_file'] ?? '/tmp/simplequeue-worker.lock';
        $this->pollTimeout = (int) ($options['poll_timeout'] ?? self::DEFAULT_POLL_TIMEOUT);
        $this->stuckJobTtl = (int) ($options['stuck_job_ttl'] ?? self::DEFAULT_STUCK_JOB_TTL);
        $this->retryBaseDelay = (int) ($options['retry_base_delay'] ?? 2);
        $this->retryMaxDelay = (int) ($options['retry_max_delay'] ?? 300);
    }

    /**
     * Run the worker loop.
     *
     * This method blocks until the worker is stopped via signal
     * or the stop() method is called.
     */
    public function run(): void
    {
        $this->logger->info('Worker starting', ['worker_id' => $this->workerId, 'queue' => $this->queue]);

        if (!$this->acquireLock()) {
            $this->logger->error('Failed to acquire singleton lock. Another worker may be running.');
            return;
        }

        $this->registerSignalHandlers();
        $this->recoverStaleJobs();

        $driverClass = get_class($this->queueManager->driver());
        $this->logger->info('Using queue driver', ['driver' => $driverClass]);

        while ($this->shouldRun) {
            $this->processNextJob();
        }

        $this->releaseLock();
        $this->logger->info('Worker stopped gracefully', ['worker_id' => $this->workerId]);
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

        $jobId = $driver->dequeue($this->queue, 0);

        if ($jobId === null) {
            return false;
        }

        $this->processJob($jobId, $driver);
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

    private function processNextJob(): void
    {
        $driver = $this->queueManager->driver();

        // Promote any delayed jobs that are now due
        if (method_exists($driver, 'promoteDelayedJobs')) {
            $driver->promoteDelayedJobs($this->queue);
        }

        $jobId = $driver->dequeue($this->queue, $this->pollTimeout);

        if ($jobId === null) {
            return;
        }

        $this->processJob($jobId, $driver);
    }

    private function processJob(int $jobId, QueueDriverInterface $driver): void
    {
        try {
            $claimed = $this->storage->claimJob($jobId, $this->workerId);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to claim job from storage', [
                'job_id' => $jobId,
                'error' => $e->getMessage(),
            ]);
            // Don't ack - let another worker try or let it recover as stale
            return;
        }

        if (!$claimed) {
            $this->logger->warning(
                'Failed to claim job, may have been claimed by another process',
                ['job_id' => $jobId]
            );
            try {
                $driver->ack($this->queue, $jobId);
            } catch (\Throwable $e) {
                $this->logger->error('Failed to ack unclaimed job', ['job_id' => $jobId, 'error' => $e->getMessage()]);
            }
            return;
        }

        try {
            $job = $this->storage->find($jobId);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to fetch job details', [
                'job_id' => $jobId,
                'error' => $e->getMessage(),
            ]);
            return;
        }

        if ($job === null) {
            $this->logger->error('Job not found after claiming', ['job_id' => $jobId]);
            try {
                $driver->ack($this->queue, $jobId);
            } catch (\Throwable $e) {
                $this->logger->error('Failed to ack missing job', ['job_id' => $jobId, 'error' => $e->getMessage()]);
            }
            return;
        }

        $this->logger->info('Processing job', [
            'job_id' => $jobId,
            'type' => $job->type,
            'attempts' => $job->attempts + 1,
        ]);

        $startTime = microtime(true);

        try {
            $this->executeJob($job);
            $duration = round(microtime(true) - $startTime, 3);

            $this->logger->info('Job completed', [
                'job_id' => $jobId,
                'type' => $job->type,
                'duration_seconds' => $duration,
            ]);

            try {
                $driver->ack($this->queue, $jobId);
            } catch (\Throwable $e) {
                $this->logger->error('Failed to ack completed job', ['job_id' => $jobId, 'error' => $e->getMessage()]);
            }
        } catch (\Throwable $e) {
            $duration = round(microtime(true) - $startTime, 3);
            $this->handleJobFailure($job, $e, $driver, $duration);
        }
    }

    private function executeJob(JobData $job): void
    {
        if (!$this->registry->has($job->type)) {
            throw HandlerNotFoundException::forType($job->type);
        }

        $handler = $this->registry->get($job->type);

        $progressCallback = function (int $percent, ?string $message = null) use ($job): void {
            $this->storage->updateProgress($job->id, $percent, $message);
        };

        $result = $handler->handle($job->id, $job->payload, $progressCallback);

        $this->storage->markCompleted($job->id, $result);
    }

    private function handleJobFailure(JobData $job, \Throwable $e, QueueDriverInterface $driver, float $duration): void
    {
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
                $delay = min($this->retryMaxDelay, (int) pow($this->retryBaseDelay, $attempts));
                $this->scheduleRetry($job, $attempts, $e);
                $driver->nack($this->queue, $job->id, $delay);
            } else {
                $this->storage->markFailed($job->id, $e->getMessage(), $this->truncateTrace($e));
                $driver->ack($this->queue, $job->id);
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

    private function scheduleRetry(JobData $job, int $attempts, \Throwable $e): void
    {
        $delay = min($this->retryMaxDelay, (int) pow($this->retryBaseDelay, $attempts));

        $this->storage->scheduleRetry($job->id, $attempts, $delay, $e->getMessage());

        $this->logger->info('Job scheduled for retry', [
            'job_id' => $job->id,
            'attempts' => $attempts,
            'delay_seconds' => $delay,
        ]);
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
