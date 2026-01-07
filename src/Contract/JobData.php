<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Contract;

/**
 * Value object representing job data.
 *
 * This class encapsulates all data associated with a queued job.
 */
final class JobData
{
    public function __construct(
        public readonly int $id,
        public readonly string $queue,
        public readonly string $type,
        public readonly string $status,
        public readonly array $payload,
        public readonly int $attempts,
        public readonly int $maxAttempts,
        public readonly ?string $availableAt = null,
        public readonly ?string $startedAt = null,
        public readonly ?string $completedAt = null,
        public readonly ?string $lockedBy = null,
        public readonly ?string $lockedAt = null,
        public readonly ?string $errorMessage = null,
        public readonly ?string $errorTrace = null,
        public readonly ?int $progress = null,
        public readonly ?string $progressMessage = null,
        public readonly mixed $result = null,
        public readonly ?string $requestId = null,
        public readonly ?string $createdAt = null,
        public readonly ?string $updatedAt = null,
    ) {}

    /**
     * Create a JobData instance from an array or object.
     *
     * @param array<string, mixed>|object $data Raw data
     * @return self
     */
    public static function fromRaw(array|object $data): self
    {
        if (is_object($data)) {
            $data = (array) $data;
        }

        $payload = $data['payload'] ?? '[]';
        if (is_string($payload)) {
            $payload = json_decode($payload, true) ?? [];
        }

        $result = $data['result'] ?? null;
        if (is_string($result) && !empty($result)) {
            $decoded = json_decode($result, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $result = $decoded;
            }
        }

        return new self(
            id: (int) ($data['id'] ?? 0),
            queue: (string) ($data['queue'] ?? 'default'),
            type: (string) ($data['type'] ?? ''),
            status: (string) ($data['status'] ?? 'pending'),
            payload: $payload,
            attempts: (int) ($data['attempts'] ?? 0),
            maxAttempts: (int) ($data['max_attempts'] ?? 3),
            availableAt: $data['available_at'] ?? null,
            startedAt: $data['started_at'] ?? null,
            completedAt: $data['completed_at'] ?? null,
            lockedBy: $data['locked_by'] ?? null,
            lockedAt: $data['locked_at'] ?? null,
            errorMessage: $data['error_message'] ?? null,
            errorTrace: $data['error_trace'] ?? null,
            progress: isset($data['progress']) ? (int) $data['progress'] : null,
            progressMessage: $data['progress_message'] ?? null,
            result: $result,
            requestId: $data['request_id'] ?? null,
            createdAt: $data['created_at'] ?? null,
            updatedAt: $data['updated_at'] ?? null,
        );
    }

    /**
     * Check if the job is in a terminal state (completed, failed, cancelled).
     */
    public function isFinished(): bool
    {
        return in_array($this->status, ['completed', 'failed', 'cancelled'], true);
    }

    /**
     * Check if the job can be retried.
     */
    public function canRetry(): bool
    {
        return $this->attempts < $this->maxAttempts;
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
            'status' => $this->status,
            'payload' => $this->payload,
            'attempts' => $this->attempts,
            'max_attempts' => $this->maxAttempts,
            'available_at' => $this->availableAt,
            'started_at' => $this->startedAt,
            'completed_at' => $this->completedAt,
            'locked_by' => $this->lockedBy,
            'locked_at' => $this->lockedAt,
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
