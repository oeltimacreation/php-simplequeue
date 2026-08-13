<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Contract;

/**
 * Typed payload for a job scheduled for another attempt event.
 */
final readonly class JobRetriedEvent extends AbstractWorkerEvent
{
    public const NAME = 'retried';

    /**
     * @param int $jobId Retried job identifier
     * @param string $type Retried job type
     * @param float $durationMs Handler duration in milliseconds
     * @param int $attempts One-based execution attempt
     * @param string $error Failure message that triggered the retry
     */
    public function __construct(
        public int $jobId,
        public string $type,
        public float $durationMs,
        public int $attempts,
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
            self::integer($data, 'attempts'),
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
            'attempts' => $this->attempts,
            'error' => $this->error,
        ];
    }
}
