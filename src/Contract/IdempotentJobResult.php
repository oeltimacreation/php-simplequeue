<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Contract;

/** Result of atomically creating or finding an active idempotent job. */
final readonly class IdempotentJobResult
{
    public function __construct(public int $jobId, public bool $created)
    {
    }
}
