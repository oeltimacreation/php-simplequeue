<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Internal;

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
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly WorkerPolicy $policy
    ) {
    }

    /**
     * Report a loop failure and return the updated infrastructure-error count.
     *
     * @param \Throwable $exception Loop failure
     * @param int $consecutiveErrors Current consecutive infrastructure-error count
     * @param callable(string, array<string, mixed>): void $emit Lifecycle event emitter
     * @return int Updated consecutive infrastructure-error count
     */
    public function handle(\Throwable $exception, int $consecutiveErrors, callable $emit): int
    {
        if (!$this->policy->isInfrastructureException($exception)) {
            $this->logger->error('Worker loop encountered an unexpected error', [
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);
            $this->sleep(1.0);
            return $consecutiveErrors;
        }

        $consecutiveErrors++;
        $delay = $this->policy->backoffDelay($consecutiveErrors);
        $totalDelaySeconds = $delay + (random_int(0, 1000) / 1000.0);
        $this->logger->error('Infrastructure error encountered. Backing off.', [
            'error' => $exception->getMessage(),
            'backoff_seconds' => round($totalDelaySeconds, 3),
            'consecutive_errors' => $consecutiveErrors,
        ]);
        $emit('infra_error', ['error' => $exception->getMessage(), 'exception' => $exception]);
        $emit('backoff', [
            'error' => $exception->getMessage(),
            'backoff_seconds' => $totalDelaySeconds,
        ]);
        $this->sleep($totalDelaySeconds);

        return $consecutiveErrors;
    }

    private function sleep(float $seconds): void
    {
        if ($seconds > 0) {
            usleep((int) ($seconds * 1_000_000));
        }
    }
}
