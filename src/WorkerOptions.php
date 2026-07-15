<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue;

use Oeltima\SimpleQueue\Contract\ClockInterface;

/** Validated worker configuration while retaining array-based construction. */
final readonly class WorkerOptions
{
    public function __construct(
        public ?string $lockFile = null,
        public int $pollTimeout = 5,
        public int $stuckJobTtl = 600,
        public int $retryBaseDelay = 2,
        public int $retryMaxDelay = 300,
        public ?ClockInterface $clock = null,
        public int $maxJobs = 0,
        public int $maxTime = 0,
        public int $memoryLimit = 0,
        public bool $stopWhenEmpty = false,
        public float $promoteInterval = 5.0,
        public float $recoveryInterval = 60.0,
        public mixed $eventListener = null
    ) {
        if ($pollTimeout < 0 || $stuckJobTtl < 1 || $retryBaseDelay < 0 || $retryMaxDelay < $retryBaseDelay) {
            throw new \InvalidArgumentException('Worker timeout, TTL, and retry delay options are invalid');
        }
        if ($maxJobs < 0 || $maxTime < 0 || $memoryLimit < 0 || $promoteInterval < 0 || $recoveryInterval < 0) {
            throw new \InvalidArgumentException('Worker limits and intervals must not be negative');
        }
        if ($eventListener !== null && !is_callable($eventListener)) {
            throw new \InvalidArgumentException('Worker event listener must be callable or null');
        }
    }

    /** @param array<string, mixed> $options */
    public static function fromArray(array $options): self
    {
        $integer = static fn (string $key, int $default): int => isset($options[$key]) && is_numeric($options[$key])
            ? (int) $options[$key]
            : $default;
        $decimal = static fn (string $key, float $default): float => isset($options[$key]) && is_numeric($options[$key])
            ? (float) $options[$key]
            : $default;
        return new self(
            lockFile: array_key_exists('lock_file', $options) && is_string($options['lock_file'])
                ? $options['lock_file']
                : null,
            pollTimeout: $integer('poll_timeout', 5),
            stuckJobTtl: $integer('stuck_job_ttl', 600),
            retryBaseDelay: $integer('retry_base_delay', 2),
            retryMaxDelay: $integer('retry_max_delay', 300),
            clock: ($options['clock'] ?? null) instanceof ClockInterface ? $options['clock'] : null,
            maxJobs: $integer('max_jobs', 0),
            maxTime: $integer('max_time', 0),
            memoryLimit: $integer('memory_limit', 0),
            stopWhenEmpty: isset($options['stop_when_empty']) ? (bool) $options['stop_when_empty'] : false,
            promoteInterval: $decimal('promote_interval', 5.0),
            recoveryInterval: $decimal('recovery_interval', 60.0),
            eventListener: $options['event_listener'] ?? null
        );
    }
}
