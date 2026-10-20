<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Contract;

/** Cooperative execution controls offered by the built-in worker context. */
interface ControlledJobContextInterface extends JobContextInterface
{
    /**
     * Return this execution's immutable claim for an application-owned child handoff.
     *
     * @return ClaimedJob Current claim; its token must never be exposed to API clients
     */
    public function getClaim(): ClaimedJob;

    /**
     * @param int $percent Percentage between 0 and 100
     * @param string|null $message Optional progress message
     */
    public function reportProgress(int $percent, ?string $message = null): void;

    /**
     * Refresh the database lease and transport visibility, or throw on lease loss.
     *
     */
    public function heartbeat(): void;

    /**
     * Check the lease, or throw before performing more application work.
     *
     */
    public function assertOwnership(): void;
}
