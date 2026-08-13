<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue;

use Oeltima\SimpleQueue\Contract\JobMiddlewareInterface;

/**
 * Ordered registry of middleware applied by a worker.
 */
final class JobMiddlewareRegistry
{
    /** @var list<JobMiddlewareInterface> */
    private array $middlewares = [];

    /**
     * Register middleware at the end of the execution pipeline.
     *
     * Middleware runs in registration order on the way in and in reverse
     * registration order on the way out.
     *
     * @param JobMiddlewareInterface $middleware Middleware to register
     */
    public function register(JobMiddlewareInterface $middleware): void
    {
        $this->middlewares[] = $middleware;
    }

    /**
     * Get middleware in deterministic registration order.
     *
     * @return list<JobMiddlewareInterface> Registered middleware
     */
    public function all(): array
    {
        return $this->middlewares;
    }

    /**
     * Remove all registered middleware.
     */
    public function clear(): void
    {
        $this->middlewares = [];
    }
}
