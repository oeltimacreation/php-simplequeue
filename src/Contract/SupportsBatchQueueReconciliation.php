<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Contract;

/**
 * Batched queue-notification reconciliation.
 *
 * Atomically checks both notification structures and restores missing IDs.
 */
interface SupportsBatchQueueReconciliation
{
    /**
     * Reconcile a page of due/future notifications in one operation.
     *
     * @param string $queue Queue name
     * @param array<int, int> $availableAtByJobId Job ID => absolute Unix timestamp
     * @param int $now Current absolute Unix timestamp
     * @param int $pendingScanLimit Maximum pending-list elements inspected per ID
     * @return list<int> IDs already present in pending or delayed notifications
     */
    public function reconcileNotifications(
        string $queue,
        array $availableAtByJobId,
        int $now,
        int $pendingScanLimit
    ): array;
}
