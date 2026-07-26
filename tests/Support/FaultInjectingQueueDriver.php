<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Support;

use Oeltima\SimpleQueue\Contract\QueueDriverInterface;
use Oeltima\SimpleQueue\Contract\SupportsBoundedQueueMembership;
use Oeltima\SimpleQueue\Contract\SupportsJobRemoval;
use Oeltima\SimpleQueue\Driver\InMemoryQueueDriver;

final class FaultInjectingQueueDriver implements
    QueueDriverInterface,
    SupportsBoundedQueueMembership,
    SupportsJobRemoval
{
    /** @param array<string, int> $failures Number of failures remaining per operation */
    public function __construct(
        public readonly InMemoryQueueDriver $inner,
        private array $failures = []
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->inner->isAvailable();
    }

    public function enqueue(string $queue, int $jobId): void
    {
        $this->failIfConfigured('enqueue');
        $this->inner->enqueue($queue, $jobId);
    }

    public function dequeue(string $queue, int $timeoutSeconds): ?int
    {
        return $this->inner->dequeue($queue, $timeoutSeconds);
    }

    public function ack(string $queue, int $jobId): void
    {
        $this->failIfConfigured('ack');
        $this->inner->ack($queue, $jobId);
    }

    public function nack(string $queue, int $jobId, int $delaySeconds = 0): void
    {
        $this->failIfConfigured('nack');
        $this->inner->nack($queue, $jobId, $delaySeconds);
    }

    public function remove(string $queue, int $jobId): void
    {
        $this->failIfConfigured('remove');
        $this->inner->remove($queue, $jobId);
    }

    public function hasPendingJob(string $queue, int $jobId, int $maxElements): bool
    {
        return $this->inner->hasPendingJob($queue, $jobId, $maxElements);
    }

    public function hasDelayedJob(string $queue, int $jobId): bool
    {
        return $this->inner->hasDelayedJob($queue, $jobId);
    }

    private function failIfConfigured(string $operation): void
    {
        $remaining = $this->failures[$operation] ?? 0;
        if ($remaining < 1) {
            return;
        }
        $this->failures[$operation] = $remaining - 1;
        throw new \RuntimeException("Injected {$operation} failure");
    }
}
