<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue;

/** Counts produced by one bounded reconciliation batch. */
final readonly class ReconcileResult
{
    public function __construct(
        public ?int $nextCursor,
        public int $scanned,
        public int $restored,
        public int $duplicates,
        public int $invalid,
        public float $durationSeconds
    ) {
    }
}
