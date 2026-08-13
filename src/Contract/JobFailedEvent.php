<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Contract;

/**
 * Typed payload for a permanently failed job event.
 */
final readonly class JobFailedEvent extends AbstractWorkerEvent
{
    public const NAME = 'failed';

    /**
     * @param int $jobId Failed job identifier
     * @param string $type Failed job type
     * @param float $durationMs Handler duration in milliseconds
     * @param string $error Failure message
     */
    public function __construct(
        public int $jobId,
        public string $type,
        public float $durationMs,
        public string $error
    ) {
    }

    /**
     * @param array<string, mixed> $data Event payload
     * @return static Typed event instance
     */
    public static function fromArray(array $data): static
    {
        return new static(
            self::integer($data, 'job_id'),
            self::string($data, 'type'),
            self::decimal($data, 'duration_ms'),
            self::string($data, 'error')
        );
    }

    /**
     * @return array<string, mixed> Event payload
     */
    protected function payload(): array
    {
        return [
            'job_id' => $this->jobId,
            'type' => $this->type,
            'duration_ms' => $this->durationMs,
            'error' => $this->error,
        ];
    }
}
