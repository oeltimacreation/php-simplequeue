<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Exception;

/**
 * Storage acknowledged creation, but queue notification may be incomplete.
 *
 * IDs do not prove a commit inside a caller-owned transaction. A failed
 * notification may have reached the server; reconcile rather than inserting again.
 */
final class JobNotificationException extends QueueException
{
    /**
     * @param list<int> $jobIds Known created job IDs
     * @param string $queue Stored notification queue
     * @param int|null $availableAt Scheduled availability, or null for immediate work
     * @param \Throwable $previous Original notification failure
     * @param list<array{jobId: int, queue: string, availableAt: int|null}> $notifications Created notification metadata
     */
    public function __construct(
        public readonly array $jobIds,
        public readonly string $queue,
        public readonly ?int $availableAt,
        \Throwable $previous,
        public readonly array $notifications = []
    ) {
        parent::__construct(
            'Job storage acknowledged creation but queue notification is uncertain: ' . $previous->getMessage(),
            0,
            $previous
        );
    }
}
