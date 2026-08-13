<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Contract;

/**
 * Typed payload for a job scheduled for another attempt event.
 */
final readonly class JobRetriedEvent extends AbstractWorkerEvent
{
    public const NAME = 'retried';

    public readonly int $jobId;
    public readonly string $type;
    public readonly float $durationMs;
    public readonly int $attempts;
    public readonly string $error;

    /**
     * @param array<string, mixed> $data Event payload
     */
    private function __construct(array $data)
    {
        $this->jobId = self::integer($data, 'job_id');
        $this->type = self::string($data, 'type');
        $this->durationMs = self::decimal($data, 'duration_ms');
        $this->attempts = self::integer($data, 'attempts');
        $this->error = self::string($data, 'error');
    }

    /**
     * @param array<string, mixed> $data Event payload
     * @return static Typed event instance
     */
    public static function fromArray(array $data): static
    {
        return new static($data);
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
