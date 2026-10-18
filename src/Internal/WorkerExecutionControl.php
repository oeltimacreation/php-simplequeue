<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Internal;

use Oeltima\SimpleQueue\Contract\ClockInterface;

/** @internal Per-execution progress buffering; never retains state across jobs. */
final class WorkerExecutionControl
{
    private ?int $percent = null;
    private ?string $message = null;
    private ?float $lastProgress = null;
    private float $lastHeartbeat;

    /**
     * @param ClockInterface $clock Monotonic clock
     * @param \Closure(int, ?string): void $write Fenced progress writer
     * @param \Closure(): void $refresh Fenced heartbeat
     * @param \Closure(): void $inspect Ownership check
     * @param float $progressInterval Minimum interval, zero for immediate updates
     * @param float $heartbeatInterval Maximum cooperative heartbeat interval
     */
    public function __construct(
        private readonly ClockInterface $clock,
        private readonly \Closure $write,
        private readonly \Closure $refresh,
        private readonly \Closure $inspect,
        private readonly float $progressInterval,
        private readonly float $heartbeatInterval
    ) {
        $this->lastHeartbeat = $clock->monotonic();
    }

    /** @param int $percent Percentage @param string|null $message Message */
    public function reportProgress(int $percent, ?string $message = null): void
    {
        JobStorageRules::validateProgressUpdate($percent, $message);
        $this->percent = $percent;
        $this->message = $message;
        $now = $this->clock->monotonic();
        if ($this->lastProgress === null || $now - $this->lastProgress >= $this->progressInterval) {
            $this->flush();
        } elseif ($now - $this->lastHeartbeat >= $this->heartbeatInterval) {
            $this->heartbeat();
        }
    }

    /** Flush the newest buffered progress before successful completion. */
    public function flush(): void
    {
        if ($this->percent === null) {
            return;
        }
        ($this->write)($this->percent, $this->message);
        $this->percent = null;
        $this->lastProgress = $this->clock->monotonic();
        $this->lastHeartbeat = $this->lastProgress;
    }

    /** Refresh visibility without generating a progress update. */
    public function heartbeat(): void
    {
        ($this->refresh)();
        $this->lastHeartbeat = $this->clock->monotonic();
    }

    /** Check ownership without reading payloads on capable storage. */
    public function assertOwnership(): void
    {
        ($this->inspect)();
    }
}
