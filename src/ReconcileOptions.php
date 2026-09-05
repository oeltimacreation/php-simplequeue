<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue;

/** Bounded reconciliation settings. The caller owns and persists the cursor. */
final readonly class ReconcileOptions
{
    public function __construct(
        public ?int $cursor = null,
        public int $pageSize = 100,
        public int $membershipScanLimit = 250,
        public float $maxDurationSeconds = 1.0
    ) {
        if ($cursor !== null && $cursor < 1) {
            throw new \InvalidArgumentException('Reconciliation cursor must be positive');
        }
        if ($pageSize < 1 || $pageSize > 10000) {
            throw new \InvalidArgumentException('Reconciliation page size must be between 1 and 10000');
        }
        if ($membershipScanLimit < 1 || $membershipScanLimit > 1000000) {
            throw new \InvalidArgumentException('Reconciliation membership scan limit must be between 1 and 1000000');
        }
        if (!is_finite($maxDurationSeconds) || $maxDurationSeconds <= 0) {
            throw new \InvalidArgumentException('Reconciliation max duration must be finite and positive');
        }
    }
}
