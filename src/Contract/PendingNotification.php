<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Contract;

/**
 * Lean pending-notification projection for reconciliation.
 *
 * Carries only the job ID and stored availability timestamp so
 * reconciliation never decodes full payload/result JSON.
 */
final readonly class PendingNotification
{
    /**
     * @param int $jobId Pending job identifier
     * @param string|null $availableAt Stored availability timestamp
     */
    public function __construct(
        public int $jobId,
        public ?string $availableAt
    ) {
    }
}
