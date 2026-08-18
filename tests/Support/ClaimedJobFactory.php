<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Support;

use Oeltima\SimpleQueue\Contract\ClaimedJob;
use Oeltima\SimpleQueue\Contract\JobData;

final class ClaimedJobFactory
{
    /**
     * Create a claimed job from the explicit state under test.
     *
     * @param JobData $job Running job data
     * @param string $workerId Worker identity
     * @param string $leaseToken Lease token
     * @return ClaimedJob Claimed job value object
     */
    public static function create(JobData $job, string $workerId, string $leaseToken): ClaimedJob
    {
        return new ClaimedJob($job, $workerId, $leaseToken);
    }
}
