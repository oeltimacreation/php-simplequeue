<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue;

/** One bounded pass; the caller persists nextCursor between passes. */
final readonly class MaintenanceResult
{
    /**
     * @param int $promoted Promoted notifications
     * @param int $recovered Recovered storage rows plus transport notifications
     * @param ReconcileResult|null $reconciliation Reconciliation outcome if attempted
     * @param int|null $nextCursor Cursor to retain, including when a phase is deferred
     * @param float $durationSeconds Elapsed pass time
     * @param bool $recoveryRan Whether the recovery phase was attempted
     * @param bool $reconciliationRan Whether capable reconciliation was attempted
     */
    public function __construct(
        public int $promoted,
        public int $recovered,
        public ?ReconcileResult $reconciliation,
        public ?int $nextCursor,
        public float $durationSeconds,
        public bool $recoveryRan,
        public bool $reconciliationRan
    ) {
    }
}
