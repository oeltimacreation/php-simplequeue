<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Contract;

/** Provides storage-level, concurrency-safe idempotent job creation. */
interface SupportsIdempotentJobCreation
{
    /**
     * @param array<string, mixed> $payload Job payload
     */
    public function createIdempotentJob(
        string $type,
        array $payload,
        string $requestId,
        string $queue,
        int $maxAttempts
    ): IdempotentJobResult;
}
