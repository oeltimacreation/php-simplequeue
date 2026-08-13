<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Contract;

/**
 * Typed value object for a worker lifecycle event.
 */
interface WorkerEventInterface
{
    /**
     * Create an event from its listener payload.
     *
     * @param array<string, mixed> $data Event payload
     * @return static Typed event instance
     */
    public static function fromArray(array $data): static;

    /**
     * Get the stable listener event name.
     *
     * @return non-empty-string Event name
     */
    public function getName(): string;

    /**
     * Convert the event to the backward-compatible listener payload.
     *
     * @return array<string, mixed> Event payload
     */
    public function toArray(): array;
}
