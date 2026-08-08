<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Support;

use Oeltima\SimpleQueue\Contract\ClaimedJob;
use Oeltima\SimpleQueue\Contract\JobData;

final class ClaimedJobFactory
{
    /**
     * Create a ClaimedJob instance with default or custom JobData, workerId, and leaseToken.
     *
     * @param JobData|null $job
     * @param string $workerId
     * @param string $leaseToken
     * @return ClaimedJob
     */
    public static function create(
        ?JobData $job = null,
        string $workerId = 'worker-1',
        string $leaseToken = 'lease-token-1'
    ): ClaimedJob {
        $job ??= JobDataFactory::running([
            'lockedBy' => $workerId,
            'leaseToken' => $leaseToken,
        ]);

        return new ClaimedJob($job, $workerId, $leaseToken);
    }
}
