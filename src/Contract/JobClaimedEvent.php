<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Contract;

/**
 * Typed payload for a successfully claimed job event.
 */
final readonly class JobClaimedEvent extends AbstractWorkerEvent
{
    public const NAME = 'claimed';

    /**
     * @param int $jobId Claimed job identifier
     * @param string $type Claimed job type
     * @param float $acquireLatencyMs Claim acquisition latency in milliseconds
     */
    public function __construct(
        public int $jobId,
        public string $type,
        public float $acquireLatencyMs
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
            self::decimal($data, 'acquire_latency_ms')
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
            'acquire_latency_ms' => $this->acquireLatencyMs,
        ];
    }
}
