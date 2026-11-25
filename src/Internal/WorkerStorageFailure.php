<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Internal;

/** @internal Retains the first fenced storage failure during one job execution. */
final class WorkerStorageFailure
{
    private ?\Throwable $cause = null;

    /**
     * @param \Closure(): bool $operation Fenced storage operation
     * @return bool Whether the claim is still owned
     */
    public function call(\Closure $operation): bool
    {
        $this->rethrow();
        try {
            return $operation();
        } catch (\Throwable $exception) {
            $this->cause = $exception;
            throw $exception;
        }
    }

    /** @throws \Throwable The original storage failure, if one occurred. */
    public function rethrow(): void
    {
        if ($this->cause !== null) {
            throw $this->cause;
        }
    }
}
