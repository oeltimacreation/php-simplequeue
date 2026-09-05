<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Internal;

use Oeltima\SimpleQueue\Contract\InfrastructureErrorEvent;
use Oeltima\SimpleQueue\Contract\SleeperInterface;
use Oeltima\SimpleQueue\Contract\WorkerBackoffEvent;
use Psr\Log\LoggerInterface;

/**
 * Reports worker-loop failures and applies the selected backoff side effect.
 *
 * @internal
 */
final class WorkerLoopFailureHandler
{
    /**
     * @param LoggerInterface $logger Worker logger
     * @param WorkerPolicy $policy Pure worker failure policy
     * @param SleeperInterface|null $sleeper Deterministic sleep boundary
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly WorkerPolicy $policy,
        private readonly ?SleeperInterface $sleeper = null
    ) {
    }

    /**
     * Report a loop failure and return the updated infrastructure-error count.
     *
     * @param \Throwable $exception Loop failure
     * @param int $consecutiveErrors Current consecutive infrastructure-error count
     * @param callable(\Oeltima\SimpleQueue\Contract\WorkerEventInterface): void $emit Lifecycle event emitter
     * @return int Updated consecutive infrastructure-error count
     */
    public function handle(\Throwable $exception, int $consecutiveErrors, callable $emit): int
    {
        $consecutiveErrors++;
        $delay = $this->policy->backoffDelay($consecutiveErrors);
        $totalDelaySeconds = $delay + (random_int(0, 1000) / 1000.0);
        $this->logger->error('Infrastructure error encountered. Backing off.', [
            'error' => $exception->getMessage(),
            'backoff_seconds' => round($totalDelaySeconds, 3),
            'consecutive_errors' => $consecutiveErrors,
        ]);
        $emit(new InfrastructureErrorEvent($exception->getMessage(), $exception::class));
        $emit(new WorkerBackoffEvent($exception->getMessage(), $totalDelaySeconds));
        $this->sleep($totalDelaySeconds);

        return $consecutiveErrors;
    }

    private function sleep(float $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }
        if ($this->sleeper !== null) {
            $this->sleeper->sleep($seconds);
            return;
        }
        usleep((int) ($seconds * 1_000_000));
    }
}
