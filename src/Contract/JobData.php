<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Contract;

use Oeltima\SimpleQueue\Exception\SerializationException;

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
        if (is_object($data)) {
            $data = (array) $data;
        }

        $payload = self::parsePayload($data['payload'] ?? '[]');
        $result = self::parseResult($data['result'] ?? null);

        $statusRaw = $data['status'] ?? 'pending';
        $status = $statusRaw instanceof JobStatus ? $statusRaw : JobStatus::from($statusRaw);

        return new self(
            id: (int) ($data['id'] ?? 0),
            queue: (string) ($data['queue'] ?? 'default'),
            type: (string) ($data['type'] ?? ''),
            status: $status,
            payload: $payload,
            attempts: (int) ($data['attempts'] ?? 0),
            maxAttempts: (int) ($data['max_attempts'] ?? 3),
            availableAt: $data['available_at'] ?? null,
            startedAt: $data['started_at'] ?? null,
            completedAt: $data['completed_at'] ?? null,
            lockedBy: $data['locked_by'] ?? null,
            lockedAt: $data['locked_at'] ?? null,
            leaseToken: $data['lease_token'] ?? null,
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
        return $this->status->isTerminal();
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

    /**
     * Parse raw payload into array.
     *
     * @param mixed $payload Raw payload
     * @return array<string, mixed>
     */
    private static function parsePayload(mixed $payload): array
    {
        if (is_string($payload)) {
            try {
                $payload = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                throw new SerializationException('Stored job payload contains invalid JSON', 0, $exception);
            }
        }
        if (!is_array($payload)) {
            throw new SerializationException('Stored job payload must decode to an object');
        }
        $normalized = [];
        foreach ($payload as $key => $value) {
            if (!is_string($key)) {
                throw new SerializationException('Stored job payload must decode to an object');
            }
            $normalized[$key] = $value;
        }
        return $normalized;
    }

    /**
     * Parse raw result into mixed structure.
     *
     * @param mixed $result Raw result
     */
    private static function parseResult(mixed $result): mixed
    {
        if (is_string($result) && $result !== '') {
            try {
                return json_decode($result, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                throw new SerializationException('Stored job result contains invalid JSON', 0, $exception);
            }
        }
        return $result;
    }
}
