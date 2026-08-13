<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Contract;

/**
 * Execution context exposed to job middleware.
 *
 * The context is immutable for the duration of one handler invocation. Its
 * continuation enters the next middleware or the handler itself.
 */
interface JobContextInterface
{
    /**
     * Get the identifier of the job being executed.
     *
     * @return int Job identifier
     */
    public function getJobId(): int;

    /**
     * Get the registered job type.
     *
     * @return string Job type identifier
     */
    public function getType(): string;

    /**
     * Get the decoded job payload.
     *
     * @return array<string, mixed> Job payload
     */
    public function getPayload(): array;

    /**
     * Get the queue used for this execution.
     *
     * @return string Queue name
     */
    public function getQueue(): string;

    /**
     * Get the one-based attempt number for this execution.
     *
     * @return int Current execution attempt
     */
    public function getAttempts(): int;

    /**
     * Continue execution through the next middleware or the job handler.
     *
     * Middleware should normally call this once between its before and after
     * logic. A value returned by the continuation is returned to the caller.
     *
     * @return mixed Result returned by the next execution stage
     */
    public function proceed(): mixed;
}
