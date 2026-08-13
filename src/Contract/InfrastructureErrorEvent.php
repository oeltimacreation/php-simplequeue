<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Contract;

/**
 * Typed payload for an infrastructure error in the worker loop.
 */
final readonly class InfrastructureErrorEvent extends AbstractWorkerEvent
{
    public const NAME = 'infra_error';

    /**
     * @param string $error Infrastructure error message
     * @param string $exceptionClass Infrastructure exception class name
     */
    public function __construct(
        public string $error,
        public string $exceptionClass
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
            self::string($data, 'exception_class')
        );
    }

    /**
     * @return array<string, mixed> Event payload
     */
    protected function payload(): array
    {
        return [
            'error' => $this->error,
            'exception_class' => $this->exceptionClass,
        ];
    }
}
