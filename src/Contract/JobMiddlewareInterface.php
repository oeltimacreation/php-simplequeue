<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Contract;

/**
 * Intercepts a job execution with before, continuation, and after logic.
 */
interface JobMiddlewareInterface
{
    /**
     * Process a job execution.
     *
     * Implementations can run before logic, call {@see JobContextInterface::proceed()},
     * and then run after logic around the remaining execution pipeline.
     * Exceptions are intentionally allowed to propagate to the worker retry
     * and permanent-failure handling path.
     *
     * @param JobContextInterface $context Execution context for the job
     * @return mixed Result returned by the middleware or continuation
     */
    public function process(JobContextInterface $context): mixed;
}
