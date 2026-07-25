<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Internal;

/**
 * Validated positive job identifier at public scalar boundaries.
 *
 * @internal
 */
final readonly class PositiveJobId
{
    private function __construct(public int $value)
    {
    }

    /**
     * Validate and normalize a public integer job identifier.
     *
     * @param int $value Raw job identifier
     * @param string $errorMessage Backward-compatible validation message
     * @return self Trusted positive job identifier
     */
    public static function fromInt(
        int $value,
        string $errorMessage = 'Job ID must be a positive integer'
    ): self {
        if ($value < 1) {
            throw new \InvalidArgumentException($errorMessage);
        }

        return new self($value);
    }
}
