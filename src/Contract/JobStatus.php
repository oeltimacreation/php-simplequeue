<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Contract;

/**
 * Represents the status of a job.
 */
enum JobStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /**
     * Check if this is a terminal status.
     *
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed,
            self::Failed,
            self::Cancelled => true,
            default => false,
        };
    }
}
