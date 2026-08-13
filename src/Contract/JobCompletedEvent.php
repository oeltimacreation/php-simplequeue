<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Contract;

/**
 * Typed payload for a successfully completed job event.
 */
final readonly class JobCompletedEvent extends AbstractWorkerEvent
{
    public const NAME = 'completed';

    /**
     * @param int $jobId Completed job identifier
     * @param string $type Completed job type
     * @param float $durationMs Handler duration in milliseconds
     */
    public function __construct(
        public int $jobId,
        public string $type,
        public float $durationMs
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
            self::decimal($data, 'duration_ms')
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
        ];
    }
}
