<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Contract;

use Oeltima\SimpleQueue\Internal\JobDataHydrator;

/**
 * Value object representing job data.
 *
 * This class encapsulates all data associated with a queued job.
 */
final readonly class JobData
{
    public function __construct(
        public int $id,
        public string $queue,
        public string $type,
        public JobStatus $status,
        /** @var array<string, mixed> */
        public array $payload,
        public int $attempts,
        public int $maxAttempts,
        public ?string $availableAt = null,
        public ?string $startedAt = null,
        public ?string $completedAt = null,
        public ?string $lockedBy = null,
        public ?string $lockedAt = null,
        public ?string $leaseToken = null,
        public ?string $errorMessage = null,
        public ?string $errorTrace = null,
        public ?int $progress = null,
        public ?string $progressMessage = null,
        public mixed $result = null,
        public ?string $requestId = null,
        public ?string $createdAt = null,
        public ?string $updatedAt = null,
    ) {
    }

    /**
     * Create a JobData instance from an array or object.
     *
     * @param array<string, mixed>|object $data Raw data
     */
    public static function fromRaw(array|object $data): self
    {
        return JobDataHydrator::hydrate($data);
    }

    /**
     * Check if the job is in a terminal state (completed, failed, cancelled).
     */
    public function isFinished(): bool
    {
        return $this->status->isTerminal();
    }

    /**
     * Check if the job can be retried.
     *
     * Only non-terminal jobs with consumed failures below max attempts retry.
     */
    public function canRetry(): bool
    {
        return !$this->status->isTerminal() && $this->attempts < $this->maxAttempts;
    }

    /**
     * Current execution ordinal exposed to handlers (persisted failures + 1).
     */
    public function currentAttempt(): int
    {
        return $this->attempts + 1;
    }

    /**
     * Convert to array representation.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'queue' => $this->queue,
            'type' => $this->type,
            'status' => $this->status->value,
            'payload' => $this->payload,
            'attempts' => $this->attempts,
            'max_attempts' => $this->maxAttempts,
            'available_at' => $this->availableAt,
            'started_at' => $this->startedAt,
            'completed_at' => $this->completedAt,
            'locked_by' => $this->lockedBy,
            'locked_at' => $this->lockedAt,
            'lease_token' => $this->leaseToken,
            'error_message' => $this->errorMessage,
            'error_trace' => $this->errorTrace,
            'progress' => $this->progress,
            'progress_message' => $this->progressMessage,
            'result' => $this->result,
            'request_id' => $this->requestId,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
