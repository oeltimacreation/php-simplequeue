<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Contract;

/**
 * Value object representing a job claim owned by a worker.
 */
final class ClaimedJob
{
    /**
     * @param JobData $job Claimed job data
     * @param string $workerId Worker that owns the claim
     * @param string $leaseToken Unique fencing token for this claim
     */
    public function __construct(
        public readonly JobData $job,
        public readonly string $workerId,
        public readonly string $leaseToken
    ) {
    }
}
