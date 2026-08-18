<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Internal;

use Oeltima\SimpleQueue\Contract\WorkerEventInterface;
use Psr\Log\LoggerInterface;

/**
 * Lazily constructs and delivers legacy worker lifecycle events.
 *
 * @internal
 */
final class WorkerEventEmitter
{
    /**
     * @param LoggerInterface $logger Logger used to isolate listener failures
     * @param mixed $listener Legacy worker event listener
     * @param \Closure|null $mirror Worker-owned listener mirror
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        mixed $listener,
        ?\Closure &$mirror
    ) {
        $this->listener = is_callable($listener) ? \Closure::fromCallable($listener) : null;
        $mirror = $this->listener;
    }

    private ?\Closure $listener;

    /**
     * Update the legacy listener without changing event delivery semantics.
     *
     * @param \Closure|null $listener Legacy worker event listener
     */
    public function setListener(?\Closure $listener): void
    {
        $this->listener = $listener;
    }

    /**
     * Determine whether lifecycle events have a configured listener.
     */
    public function isListening(): bool
    {
        return $this->listener !== null;
    }

    /**
     * Deliver an already-created typed event.
     *
     * @param WorkerEventInterface $event Typed worker event
     */
    public function emit(WorkerEventInterface $event): void
    {
        if ($this->listener === null) {
            return;
        }

        try {
            ($this->listener)($event->getName(), $event->toArray());
        } catch (\Throwable $listenerError) {
            $this->logger->error('Worker event listener threw an exception', [
                'event' => $event->getName(),
                'error' => $listenerError->getMessage()
            ]);
        }
    }
}
