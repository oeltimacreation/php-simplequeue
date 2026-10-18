<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue;

/** Explicit work bounds for an application-owned maintenance pass. */
final readonly class MaintenanceOptions
{
    /**
     * @param int $stuckJobTtl Stale lease threshold, seconds
     * @param int $promoteLimit Maximum delayed notifications promoted
     * @param int $recoveryLimit Maximum stale rows/notifications per backend
     * @param int $pageSize Reconciliation page size
     * @param int $membershipScanLimit Maximum pending membership window
     * @param float $maxDurationSeconds Cooperative per-pass deadline
     */
    public function __construct(
        public int $stuckJobTtl = 600,
        public int $promoteLimit = 100,
        public int $recoveryLimit = 100,
        public int $pageSize = 100,
        public int $membershipScanLimit = 250,
        public float $maxDurationSeconds = 1.0
    ) {
        if ($stuckJobTtl < 1 || $promoteLimit < 1 || $recoveryLimit < 1) {
            throw new \InvalidArgumentException('Maintenance TTL and batch limits must be positive');
        }
        if ($promoteLimit > 10000 || $recoveryLimit > 10000) {
            throw new \InvalidArgumentException('Maintenance batches must not exceed 10000');
        }
        new ReconcileOptions(null, $pageSize, $membershipScanLimit, $maxDurationSeconds);
    }
}
