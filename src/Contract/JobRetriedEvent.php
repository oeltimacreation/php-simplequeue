<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Contract;

/**
 * Typed payload for a job scheduled for another attempt event.
 *
 * @phpstan-type PayloadShape array{
 *     job_id: int,
 *     type: string,
 *     duration_ms: float,
 *     attempts: int,
 *     error: string
 * }
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
     * @param PayloadShape $data Validated event payload
     */
    private function __construct(array $data)
    {
        $this->jobId = $data['job_id'];
        $this->type = $data['type'];
        $this->durationMs = $data['duration_ms'];
        $this->attempts = $data['attempts'];
        $this->error = $data['error'];
    }

    /**
     * @param array<string, mixed> $data Event payload
     * @return static Typed event instance
     */
    public static function fromArray(array $data): static
    {
        /** @var PayloadShape $validated */
        $validated = [
            'job_id' => self::integer($data, 'job_id'),
            'type' => self::string($data, 'type'),
            'duration_ms' => self::decimal($data, 'duration_ms'),
            'attempts' => self::integer($data, 'attempts'),
            'error' => self::string($data, 'error'),
        ];

        return new static($validated);
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
