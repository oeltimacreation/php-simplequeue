<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Contract;

/** Identifies a successful storage mutation inside its uncommitted transaction. */
final readonly class StorageTransition
{
    /**
     * @param int $jobId Affected job ID
     * @param string $operation Storage operation; inspect state on the supplied connection
     */
    public function __construct(public int $jobId, public string $operation)
    {
    }
}
