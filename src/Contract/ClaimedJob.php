<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Contract;

/**
 * Value object representing a job claim owned by a worker.
 */
final readonly class ClaimedJob
{
    /**
     * @param JobData $job Claimed job data
     * @param string $workerId Worker that owns the claim
     * @param string $leaseToken Unique fencing token for this claim
     */
    public function __construct(
        public JobData $job,
        public string $workerId,
        public string $leaseToken
    ) {
    }
}
