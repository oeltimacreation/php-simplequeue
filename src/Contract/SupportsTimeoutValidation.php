<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Contract;

/**
 * Interface for queue drivers that support poll timeout validation.
 */
interface SupportsTimeoutValidation
{
    /**
     * Validate that the poll timeout is safe relative to the driver connection limits.
     *
     * @param int $pollTimeout Seconds the worker will block waiting for a job
     * @throws \InvalidArgumentException If the timeout configuration is unsafe
     */
    public function validateTimeout(int $pollTimeout): void;
}
