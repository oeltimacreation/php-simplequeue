<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Contract;

/**
 * Typed payload for a worker losing a job lease before a state transition.
 */
final readonly class JobLostOwnershipEvent extends AbstractWorkerEvent
{
    public const NAME = 'lost_ownership';

    /**
     * @param int $jobId Job that lost ownership
     * @param string $type Job type
     * @param string $context State transition context
     */
    public function __construct(
        public int $jobId,
        public string $type,
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
            self::string($data, 'type'),
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
            'type' => $this->type,
            'context' => $this->context,
        ];
    }
}
