<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Contract;

/**
 * Shared implementation for typed worker event value objects.
 *
 * @internal
 */
abstract readonly class AbstractWorkerEvent implements WorkerEventInterface
{
    public const NAME = '';

    /**
     * Create an event from its listener payload.
     *
     * @param array<string, mixed> $data Event payload
     * @return static Typed event instance
     */
    abstract public static function fromArray(array $data): static;

    /**
     * Get the stable listener event name.
     *
     * @return non-empty-string Event name
     */
    final public function getName(): string
    {
        return static::NAME;
    }

    /**
     * Convert the event to the backward-compatible listener payload.
     *
     * @return array<string, mixed> Event payload
     */
    final public function toArray(): array
    {
        return $this->payload();
    }

    /**
     * Build the event's serialized payload.
     *
     * @return array<string, mixed> Event payload
     */
    abstract protected function payload(): array;

    /**
     * Read an integer event field.
     *
     * @param array<string, mixed> $data Event payload
     * @param string $key Field name
     */
    protected static function integer(array $data, string $key): int
    {
        $value = self::field($data, $key);
        if (!is_int($value)) {
            throw self::invalidField($key, 'integer');
        }

        return $value;
    }

    /**
     * Read a numeric event field as a float.
     *
     * @param array<string, mixed> $data Event payload
     * @param string $key Field name
     */
    protected static function decimal(array $data, string $key): float
    {
        $value = self::field($data, $key);
        if (!is_int($value) && !is_float($value)) {
            throw self::invalidField($key, 'number');
        }

        return (float) $value;
    }

    /**
     * Read a string event field.
     *
     * @param array<string, mixed> $data Event payload
     * @param string $key Field name
     */
    protected static function string(array $data, string $key): string
    {
        $value = self::field($data, $key);
        if (!is_string($value)) {
            throw self::invalidField($key, 'string');
        }

        return $value;
    }

    /**
     * Read a required event field.
     *
     * @param array<string, mixed> $data Event payload
     * @param string $key Field name
     */
    private static function field(array $data, string $key): mixed
    {
        if (!array_key_exists($key, $data)) {
            throw new \InvalidArgumentException(sprintf('Worker event field "%s" is required.', $key));
        }

        return $data[$key];
    }

    /**
     * Build a consistent invalid-field exception.
     */
    private static function invalidField(string $key, string $expected): \InvalidArgumentException
    {
        return new \InvalidArgumentException(sprintf('Worker event field "%s" must be a %s.', $key, $expected));
    }
}
