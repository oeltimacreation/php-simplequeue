<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Internal;

/** @internal Fenced storage operations for one execution. */
final readonly class WorkerExecutionCallbacks
{
    /**
     * @param \Closure(int, ?string): void $write Progress writer
     * @param \Closure(): void $refresh Heartbeat writer
     * @param \Closure(): void $inspect Ownership check
     */
    public function __construct(
        public \Closure $write,
        public \Closure $refresh,
        public \Closure $inspect
    ) {
    }
}
