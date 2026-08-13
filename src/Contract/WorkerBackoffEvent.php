<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Contract;

/**
 * Typed payload for an infrastructure backoff event.
 */
final readonly class WorkerBackoffEvent extends AbstractWorkerEvent
{
    public const NAME = 'backoff';

    /**
     * @param string $error Infrastructure error message
     * @param float $backoffSeconds Applied backoff duration in seconds
     */
    public function __construct(
        public string $error,
        public float $backoffSeconds
    ) {
    }

    /**
     * @param array<string, mixed> $data Event payload
     * @return static Typed event instance
     */
    public static function fromArray(array $data): static
    {
        return new static(
            self::string($data, 'error'),
            self::decimal($data, 'backoff_seconds')
        );
    }

    /**
     * @return array<string, mixed> Event payload
     */
    protected function payload(): array
    {
        return [
            'error' => $this->error,
            'backoff_seconds' => $this->backoffSeconds,
        ];
    }
}
