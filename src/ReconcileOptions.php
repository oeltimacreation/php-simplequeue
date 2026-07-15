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
        if (
            ($cursor !== null && $cursor < 1)
            || $pageSize < 1
            || $membershipScanLimit < 1
            || $maxDurationSeconds <= 0
        ) {
            throw new \InvalidArgumentException('Reconciliation cursor and limits must be positive');
        }
    }
}
