<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue;

use Oeltima\SimpleQueue\Contract\ClaimedJob;
use Oeltima\SimpleQueue\Contract\ClockInterface;
use Oeltima\SimpleQueue\Contract\JobClaimedEvent;
use Oeltima\SimpleQueue\Contract\JobStorageInterface;
use Oeltima\SimpleQueue\Contract\QueueDriverInterface;
use Oeltima\SimpleQueue\Contract\SleeperInterface;
use Oeltima\SimpleQueue\Contract\SupportsBoundedQueueMembership;
use Oeltima\SimpleQueue\Contract\SupportsClaimedDequeue;
use Oeltima\SimpleQueue\Contract\SupportsDelayedJobs;
use Oeltima\SimpleQueue\Contract\SupportsPendingJobCursor;
use Oeltima\SimpleQueue\Contract\SupportsPendingNotificationCursor;
use Oeltima\SimpleQueue\Contract\SupportsQueueScopedStaleRecovery;
use Oeltima\SimpleQueue\Contract\SupportsStaleRecovery;
use Oeltima\SimpleQueue\Contract\SupportsTimeoutValidation;
use Oeltima\SimpleQueue\Contract\SupportsWorkerAwareClaimedDequeue;
use Oeltima\SimpleQueue\Contract\SupportsWorkerId;
use Oeltima\SimpleQueue\Internal\WorkerEventEmitter;
use Oeltima\SimpleQueue\Internal\WorkerJobProcessor;
use Oeltima\SimpleQueue\Internal\WorkerJobProcessorDependencies;
use Oeltima\SimpleQueue\Internal\WorkerLoopFailureHandler;
use Oeltima\SimpleQueue\Internal\WorkerPolicy;
use Oeltima\SimpleQueue\Internal\WorkerProcessControl;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Background worker that processes jobs from the queue.
 *
 * Effect ordering per claimed job is durable transition, lifecycle event,
 * then ACK/NACK. Lost ownership permits no notification cleanup.
 */
final class Worker
{
    public const EXIT_SUCCESS = 0;
    public const EXIT_ERROR = 1;
    public const EXIT_LOCK_UNAVAILABLE = 2;

    private readonly LoggerInterface $logger;
    private readonly string $workerId;
    private bool $shouldRun = true;
    private readonly ?string $lockFile;
    private readonly WorkerOptions $options;
    private readonly ClockInterface $clock;
    private readonly SleeperInterface $sleeper;
    private readonly WorkerPolicy $policy;
    private readonly WorkerEventEmitter $eventEmitter;
    private readonly WorkerJobProcessor $jobProcessor;
    private readonly WorkerLoopFailureHandler $loopFailureHandler;
    private readonly WorkerProcessControl $processControl;
    private int $processedJobsCount = 0;
    private float $startTime = 0.0;
    private float $lastPromoteTime = 0.0;
    private float $lastRecoveryTime = 0.0;
    private ?int $reconcileCursor = null;
    private bool $executing = false;
    private bool $shutdownReleaseFailed = false;

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
        if ($driver instanceof SupportsWorkerId && !$driver instanceof SupportsWorkerAwareClaimedDequeue) {
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
        $this->eventEmitter = new WorkerEventEmitter($this->logger, $this->options->eventListener);
        $this->jobProcessor = new WorkerJobProcessor(new WorkerJobProcessorDependencies([
            'storage' => $this->storage,
            'registry' => $this->registry,
            'logger' => $this->logger,
            'queue' => $this->queue,
            'policy' => $this->policy,
            'clock' => $this->clock,
            'eventEmitter' => $this->eventEmitter,
        ]));
        $this->processControl = new WorkerProcessControl($this->logger, $this->lockFile, $this->workerId);
    }

    /** @param array<string, mixed>|WorkerOptions $options */
    private function resolveLockFile(string $queue, array|WorkerOptions $options): ?string
    {
        if ($options instanceof WorkerOptions) {
            return self::typedLockFile($queue, $options);
        }
        return $this->arrayLockFile($queue, $options);
    }

    private static function typedLockFile(string $queue, WorkerOptions $options): ?string
    {
        if (!$options->lockingEnabled) {
            return null;
        }
        return $options->lockFile ?? self::defaultLockFile($queue);
    }

    /** @param array<string, mixed> $options */
    private function arrayLockFile(string $queue, array $options): ?string
    {
        if (array_key_exists('lock_file', $options)) {
            return $this->explicitLockFile($options['lock_file']);
        }
        if (array_key_exists('locking_enabled', $options) && $options['locking_enabled'] === false) {
            return null;
        }
        if ($this->options->lockFile !== null) {
            return $this->options->lockFile;
        }
        return $this->options->lockingEnabled ? self::defaultLockFile($queue) : null;
    }

    private function explicitLockFile(mixed $lockFile): ?string
    {
        if ($lockFile === null) {
            return null;
        }
        return is_string($lockFile) && trim($lockFile) !== '' ? $lockFile : $this->options->lockFile;
    }

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
        $directory = rtrim(sys_get_temp_dir(), '/') . '/simplequeue-' . $uid;
        $workingDirectory = getcwd();
        $scope = ($workingDirectory !== false ? $workingDirectory : '.') . "\0" . $queue;
        return $directory . '/worker-' . hash('sha256', $scope) . '.lock';
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

    /** @param callable(string, array<string, mixed>): void $listener */
    public function setEventListener(callable $listener): void
    {
        $this->eventEmitter->setListener(\Closure::fromCallable($listener));
    }

    /** @return int Exit code */
    public function run(): int
    {
        if ($this->executing) {
            throw new \LogicException('Worker is already executing');
        }
        $this->resetRunState();
        $this->logger->info('Worker starting', ['worker_id' => $this->workerId, 'queue' => $this->queue]);
        if (!$this->processControl->acquireLock()) {
            $this->executing = false;
            $this->logger->error('Failed to acquire singleton lock. Another worker may be running.');
            return self::EXIT_LOCK_UNAVAILABLE;
        }

        try {
            $this->initializeRun();
            $this->runLoop();
        } catch (\Throwable $exception) {
            $this->logger->critical('Worker encountered a fatal error', ['error' => $exception->getMessage()]);
            return self::EXIT_ERROR;
        } finally {
            $this->processControl->restoreSignalHandlers();
            $this->processControl->releaseLock();
            $this->executing = false;
        }

        if ($this->shutdownReleaseFailed) {
            $this->logger->error('Worker shutdown release failed; supervisor should react');
            return self::EXIT_ERROR;
        }
        $this->logger->info('Worker stopped gracefully', ['worker_id' => $this->workerId]);
        return self::EXIT_SUCCESS;
    }

    private function resetRunState(): void
    {
        $this->executing = true;
        $this->shouldRun = true;
        $this->processedJobsCount = 0;
        $this->reconcileCursor = null;
        $this->shutdownReleaseFailed = false;
    }

    private function initializeRun(): void
    {
        $this->processControl->registerSignalHandlers(function (int $signal): void {
            $signalName = $signal === SIGTERM ? 'SIGTERM' : 'SIGINT';
            $this->logger->info("Received {$signalName}, shutting down after current job...");
            $this->shouldRun = false;
        });
        // Promote before reconciliation so one due delayed job cannot gain a second notification.
        $this->promoteDelayedJobs();
        $this->lastPromoteTime = $this->clock->monotonic();
        $this->recoverStaleJobs();
        $this->reconcileDbAndRedis();
        $this->lastRecoveryTime = $this->clock->monotonic();
        $this->logger->info('Using queue driver', ['driver' => $this->queueManager->driver()::class]);
        $this->startTime = $this->clock->monotonic();
    }

    private function runLoop(): void
    {
        $consecutiveErrors = 0;
        while ($this->shouldRun && !$this->limitsReached()) {
            try {
                $this->runDueMaintenance();
                if (!$this->runNextIteration($this->queueManager->driver())) {
                    return;
                }
                $consecutiveErrors = 0;
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
            return $this->continueAfterEmptyQueue();
        }
        if (!$this->shouldRun) {
            $this->shutdownReleaseFailed = !$this->releaseClaimForShutdown($claim, $driver);
            return false;
        }

        $this->processedJobsCount++;
        $this->jobProcessor->process($claim, $driver);
        return true;
    }

    private function continueAfterEmptyQueue(): bool
    {
        if (!$this->options->stopWhenEmpty) {
            return true;
        }
        $this->logger->info('Queue is empty and stop_when_empty is enabled. Stopping worker.');
        return false;
    }

    private function releaseClaimForShutdown(ClaimedJob $claim, QueueDriverInterface $driver): bool
    {
        $this->logger->info('Worker shutting down, releasing claimed job', ['job_id' => $claim->job->id]);
        try {
            $released = $this->storage->scheduleRetry($claim, $claim->job->attempts, 0, $claim->job->errorMessage);
        } catch (\Throwable $exception) {
            $this->logger->error('Failed to release job during shutdown', [
                'job_id' => $claim->job->id,
                'error' => $exception->getMessage(),
            ]);
            return false;
        }
        if (!$released) {
            $this->jobProcessor->emitLostOwnership($claim, 'shutdown_release');
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

    /** @return bool True when a job was processed; false only when none was available */
    public function processOne(): bool
    {
        if ($this->executing) {
            throw new \LogicException('Worker is already executing');
        }
        $this->executing = true;
        try {
            $driver = $this->queueManager->driver();
            if ($driver instanceof SupportsDelayedJobs) {
                $driver->promoteDelayedJobs($this->queue, $this->options->promoteLimit);
            }
            $claim = $this->claimNextJob(0);
            if ($claim === null) {
                return false;
            }
            $this->processedJobsCount++;
            $this->jobProcessor->process($claim, $driver);
            return true;
        } finally {
            $this->executing = false;
        }
    }

    public function stop(): void
    {
        $this->shouldRun = false;
    }

    public function getWorkerId(): string
    {
        return $this->workerId;
    }

    private function claimNextJob(int $timeoutSeconds): ?ClaimedJob
    {
        $started = $this->clock->monotonic();
        $driver = $this->queueManager->driver();
        $jobId = null;
        try {
            if ($driver instanceof SupportsWorkerAwareClaimedDequeue) {
                $claim = $driver->dequeueClaimedForWorker($this->queue, $timeoutSeconds, $this->workerId);
                return $this->claimedOrNull($claim, $started);
            }
            if ($driver instanceof SupportsClaimedDequeue) {
                $claim = $driver->dequeueClaimed($this->queue, $timeoutSeconds);
                return $this->claimedOrNull($claim, $started);
            }
            $jobId = $driver->dequeue($this->queue, $timeoutSeconds);
            if ($jobId === null) {
                return null;
            }
            $claim = $this->storage->claimById($jobId, $this->workerId);
        } catch (\Throwable $exception) {
            if ($jobId !== null) {
                $this->handlePostPopClaimFailure($driver, $jobId, $exception);
            }
            throw $exception;
        }

        if ($claim === null) {
            $this->handleUnclaimedJobAck($driver, $jobId);
            return null;
        }
        $this->emitClaimedEvent($claim, ($this->clock->monotonic() - $started) * 1000.0);
        return $claim;
    }

    private function claimedOrNull(?ClaimedJob $claim, float $started): ?ClaimedJob
    {
        if ($claim !== null) {
            $this->emitClaimedEvent($claim, ($this->clock->monotonic() - $started) * 1000.0);
        }
        return $claim;
    }

    private function emitClaimedEvent(ClaimedJob $claim, float $latency): void
    {
        if ($this->eventEmitter->isListening()) {
            $this->eventEmitter->emit(new JobClaimedEvent($claim->job->id, $claim->job->type, $latency));
        }
    }

    private function handlePostPopClaimFailure(
        QueueDriverInterface $driver,
        int $jobId,
        \Throwable $exception
    ): void {
        $this->logger->error('Failed to claim job from storage', [
            'job_id' => $jobId,
            'error' => $exception->getMessage(),
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

    private function handleUnclaimedJobAck(QueueDriverInterface $driver, int $jobId): void
    {
        $this->logger->warning(
            'Failed to claim job, may have been claimed by another process',
            ['job_id' => $jobId]
        );
        try {
            $driver->ack($this->queue, $jobId);
        } catch (\Throwable $exception) {
            $this->logger->error('Failed to ack unclaimed job', [
                'job_id' => $jobId,
                'error' => $exception->getMessage(),
            ]);
            throw $exception;
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
        $limitBytes = $this->memoryLimitBytes();
        if ($limitBytes !== null && memory_get_usage(true) >= $limitBytes) {
            $this->logger->info('Worker limit reached: memory_limit', [
                'memory_limit' => $this->options->memoryLimit,
                'current_memory' => memory_get_usage(true),
            ]);
            return true;
        }
        return false;
    }

    private function memoryLimitBytes(): ?int
    {
        if ($this->options->memoryLimit <= 0) {
            return null;
        }
        if ($this->options->memoryLimit > intdiv(PHP_INT_MAX, 1048576)) {
            return PHP_INT_MAX;
        }
        return $this->options->memoryLimit * 1048576;
    }

    private function runDueMaintenance(): void
    {
        $now = $this->clock->monotonic();
        if ($now - $this->lastPromoteTime >= $this->options->promoteInterval) {
            $this->promoteDelayedJobs();
            $this->lastPromoteTime = $now;
        }
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
            $driver->promoteDelayedJobs($this->queue, $this->options->promoteLimit);
        }
    }

    private function recoverStaleJobs(): void
    {
        $ttl = $this->options->stuckJobTtl;
        $recovered = $this->storage instanceof SupportsQueueScopedStaleRecovery
            ? $this->storage->recoverStaleJobsForQueue($this->queue, $ttl, 100)
            : $this->storage->recoverStaleJobs($ttl);
        $driver = $this->queueManager->driver();
        if ($driver instanceof SupportsStaleRecovery) {
            $recovered += $driver->recoverStaleProcessing($this->queue, $ttl);
        }
        if ($recovered > 0) {
            $this->logger->warning('Recovered stale jobs', ['count' => $recovered, 'ttl_seconds' => $ttl]);
        }
    }

    private function reconcileDbAndRedis(): void
    {
        $driver = $this->queueManager->driver();
        $storage = $this->storage;
        if (!$driver instanceof SupportsBoundedQueueMembership) {
            return;
        }
        if (!$storage instanceof SupportsPendingNotificationCursor && !$storage instanceof SupportsPendingJobCursor) {
            return;
        }

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
    }

    private function generateWorkerId(): string
    {
        $host = gethostname();
        $hostname = substr($host === false ? 'unknown' : $host, 0, 200);
        return substr(sprintf('%s:%d:%s', $hostname, getmypid(), bin2hex(random_bytes(8))), 0, 255);
    }
}
