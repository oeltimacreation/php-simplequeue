<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Contract;

/**
 * Typed payload for an infrastructure failure during claimed-job processing.
 */
final readonly class InfrastructureFailureEvent extends AbstractWorkerEvent
{
    public const NAME = 'infrastructure_failure';

    /**
     * @param int $jobId Affected job identifier
     * @param string $context Infrastructure failure context
     */
    public function __construct(
        public int $jobId,
        public string $context
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
            self::string($data, 'context')
        );
    }

    /**
     * @return array<string, mixed> Event payload
     */
    protected function payload(): array
    {
        return [
            'job_id' => $this->jobId,
            'context' => $this->context,
        ];
    }
}
