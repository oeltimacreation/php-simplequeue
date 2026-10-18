<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Contract;

/** @phpstan-import-type JobDefinitionShape from JobStorageInterface */
interface SupportsIdempotentBatchCreation
{
    /**
     * Create one atomic logical batch, deduplicating active request IDs.
     *
     * @param array<int, JobDefinitionShape> $jobs Definitions with nonempty requestId
     * @return list<IdempotentJobResult> Input-ordered outcomes; repeated keys create once
     */
    public function createIdempotentJobs(array $jobs): array;
}
