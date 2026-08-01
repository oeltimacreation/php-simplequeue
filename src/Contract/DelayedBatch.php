<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Contract;

/**
 * A batch of job notifications scheduled for a shared availability time.
 *
 * @immutable
 */
final readonly class DelayedBatch
{
    /**
     * @param int[] $jobIds Job identifiers to notify
     * @param string $queue Queue name
     * @param int $availableAt Unix timestamp when the jobs become available
     */
    public function __construct(
        public array $jobIds,
        public string $queue,
        public int $availableAt
    ) {
    }
}
