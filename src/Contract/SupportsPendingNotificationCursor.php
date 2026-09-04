<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Contract;

/**
 * Lean keyset cursor over pending notifications.
 *
 * The cursor is exclusive and returns ascending job IDs, matching the
 * existing full-job cursor.
 */
interface SupportsPendingNotificationCursor
{
    /**
     * Scan pending notifications without decoding payload/result JSON.
     *
     * @param string $queue Queue name
     * @param int|null $afterId Exclusive keyset cursor
     * @param int $limit Maximum rows to return
     * @return list<PendingNotification> Pending projections in ascending ID order
     */
    public function scanPendingNotifications(
        string $queue,
        ?int $afterId,
        int $limit
    ): array;
}
