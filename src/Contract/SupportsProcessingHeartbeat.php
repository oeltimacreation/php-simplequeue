<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Contract;

/** Refreshes queue-driver processing visibility for a fenced storage claim. */
interface SupportsProcessingHeartbeat
{
    /**
     * @param string $queue Queue name
     * @param int $jobId Positive job ID
     */
    public function heartbeatProcessing(string $queue, int $jobId): void;
}
