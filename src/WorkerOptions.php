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
        public int $promoteLimit = 100,
        public float $recoveryInterval = 60.0,
        public mixed $eventListener = null
    ) {
        if ($pollTimeout < 0 || $stuckJobTtl < 1 || $retryBaseDelay < 0 || $retryMaxDelay < $retryBaseDelay) {
            throw new \InvalidArgumentException('Worker timeout, TTL, and retry delay options are invalid');
        }
        if (
            $maxJobs < 0
            || $maxTime < 0
            || $memoryLimit < 0
            || $promoteInterval < 0
            || $recoveryInterval < 0
            || $promoteLimit < 1
        ) {
            throw new \InvalidArgumentException('Worker limits, intervals, and promote limit must be valid');
        }
        if ($eventListener !== null && !is_callable($eventListener)) {
            throw new \InvalidArgumentException('Worker event listener must be callable or null');
        }
    }

    /** @param array<string, mixed> $options */
    public static function fromArray(array $options): self
    {
        return new self(
            lockFile: array_key_exists('lock_file', $options) && is_string($options['lock_file'])
                ? $options['lock_file']
                : null,
            pollTimeout: self::integerOption($options, 'poll_timeout', 5),
            stuckJobTtl: self::integerOption($options, 'stuck_job_ttl', 600),
            retryBaseDelay: self::integerOption($options, 'retry_base_delay', 2),
            retryMaxDelay: self::integerOption($options, 'retry_max_delay', 300),
            clock: ($options['clock'] ?? null) instanceof ClockInterface ? $options['clock'] : null,
            maxJobs: self::integerOption($options, 'max_jobs', 0),
            maxTime: self::integerOption($options, 'max_time', 0),
            memoryLimit: self::integerOption($options, 'memory_limit', 0),
            stopWhenEmpty: isset($options['stop_when_empty']) ? (bool) $options['stop_when_empty'] : false,
            promoteInterval: self::decimalOption($options, 'promote_interval', 5.0),
            promoteLimit: self::integerOption($options, 'promote_limit', 100),
            recoveryInterval: self::decimalOption($options, 'recovery_interval', 60.0),
            eventListener: $options['event_listener'] ?? null
        );
    }

    /**
     * Resolve a numeric integer option with its default.
     *
     * @param array<string, mixed> $options Raw worker options
     * @param string $key Option key
     * @param int $default Default when absent or non-numeric
     */
    private static function integerOption(array $options, string $key, int $default): int
    {
        return isset($options[$key]) && is_numeric($options[$key]) ? (int) $options[$key] : $default;
    }

    /**
     * Resolve a numeric decimal option with its default.
     *
     * @param array<string, mixed> $options Raw worker options
     * @param string $key Option key
     * @param float $default Default when absent or non-numeric
     */
    private static function decimalOption(array $options, string $key, float $default): float
    {
        return isset($options[$key]) && is_numeric($options[$key]) ? (float) $options[$key] : $default;
    }
}
