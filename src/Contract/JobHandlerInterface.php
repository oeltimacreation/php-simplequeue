<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Contract;

/**
 * Interface for job handler implementations.
 *
 * Job handlers contain the business logic for processing specific job types.
 * Each job type should have a corresponding handler class.
 */
interface JobHandlerInterface
{
    /**
     * Handle a background job.
     *
     * @param int $jobId The job identifier
     * @param array<string, mixed> $payload The job payload data
     * @param callable|null $progressCallback Optional callback to report progress
     *                                        Signature: fn(int $percent, ?string $message): void
     * @return mixed Result data to store with the completed job
     * @throws \Throwable On job failure
     */
    public function handle(int $jobId, array $payload, ?callable $progressCallback = null): mixed;
}
