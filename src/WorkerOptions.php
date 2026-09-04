<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue;

use Oeltima\SimpleQueue\Contract\ClockInterface;
use Oeltima\SimpleQueue\Contract\SleeperInterface;

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
        public mixed $eventListener = null,
        public bool $lockingEnabled = true,
        public ?SleeperInterface $sleeper = null
    ) {
        if ($pollTimeout < 0 || $stuckJobTtl < 1 || $retryBaseDelay < 0 || $retryMaxDelay < $retryBaseDelay) {
            throw new \InvalidArgumentException('Worker timeout, TTL, and retry delay options are invalid');
        }
        if ($maxJobs < 0 || $maxTime < 0 || $memoryLimit < 0 || $promoteInterval < 0 || $recoveryInterval < 0) {
            throw new \InvalidArgumentException('Worker limits and intervals must not be negative');
        }
        self::assertPromoteLimit($promoteLimit);
        if ($eventListener !== null && !is_callable($eventListener)) {
            throw new \InvalidArgumentException('Worker event listener must be callable or null');
        }
        if (!$lockingEnabled && $lockFile !== null) {
            throw new \InvalidArgumentException('Worker locking cannot be both disabled and given a custom lock path');
        }
        if ($lockFile !== null && trim($lockFile) === '') {
            throw new \InvalidArgumentException('Worker custom lock path must not be empty');
        }
    }

    /** @param array<string, mixed> $options */
    public static function fromArray(array $options): self
    {
        $lockingEnabled = true;
        $lockFile = null;
        if (array_key_exists('lock_file', $options)) {
            $rawLock = $options['lock_file'];
            if ($rawLock === null) {
                // Explicit null disables locking (array form only).
                $lockingEnabled = false;
            } elseif (is_string($rawLock) && trim($rawLock) !== '') {
                $lockFile = $rawLock;
            } elseif (is_string($rawLock)) {
                throw new \InvalidArgumentException('Worker custom lock path must not be empty');
            } else {
                throw new \InvalidArgumentException('Worker lock_file must be a non-empty string or null');
            }
        }
        if (array_key_exists('locking_enabled', $options)) {
            $rawEnabled = $options['locking_enabled'];
            if (!is_bool($rawEnabled)) {
                throw new \InvalidArgumentException('Worker locking_enabled must be a boolean');
            }
            $lockingEnabled = $rawEnabled;
        }
        if (!$lockingEnabled && $lockFile !== null) {
            throw new \InvalidArgumentException('Worker locking cannot be both disabled and given a custom lock path');
        }
        $sleeperRaw = $options['sleeper'] ?? null;
        if ($sleeperRaw !== null && !$sleeperRaw instanceof SleeperInterface) {
            throw new \InvalidArgumentException('Worker sleeper must be a SleeperInterface instance or null');
        }

        $clockRaw = $options['clock'] ?? null;
        $clock = $clockRaw instanceof ClockInterface ? $clockRaw : self::strictClock($options);

        return new self(
            lockFile: $lockFile,
            pollTimeout: self::integerOption($options, 'poll_timeout', 5),
            stuckJobTtl: self::integerOption($options, 'stuck_job_ttl', 600),
            retryBaseDelay: self::integerOption($options, 'retry_base_delay', 2),
            retryMaxDelay: self::integerOption($options, 'retry_max_delay', 300),
            clock: $clock,
            maxJobs: self::integerOption($options, 'max_jobs', 0),
            maxTime: self::integerOption($options, 'max_time', 0),
            memoryLimit: self::integerOption($options, 'memory_limit', 0),
            stopWhenEmpty: self::booleanOption($options, 'stop_when_empty', false),
            promoteInterval: self::decimalOption($options, 'promote_interval', 5.0),
            promoteLimit: self::integerOption($options, 'promote_limit', 100),
            recoveryInterval: self::decimalOption($options, 'recovery_interval', 60.0),
            eventListener: self::strictListener($options),
            lockingEnabled: $lockingEnabled,
            sleeper: $sleeperRaw
        );
    }

    /**
     * Resolve a strict integer option (int or canonical base-10 string).
     *
     * @param array<string, mixed> $options Raw worker options
     * @param string $key Option key
     * @param int $default Default when absent
     */
    private static function integerOption(array $options, string $key, int $default): int
    {
        if (!array_key_exists($key, $options)) {
            return $default;
        }
        $value = $options[$key];
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^(0|[1-9][0-9]*)$/', $value) === 1) {
            $parsed = (int) $value;
            // Guard against overflow string that PHP silently clamps.
            if ((string) $parsed === ltrim($value, '0') || $value === '0') {
                return $parsed;
            }
            // Fall through to invalid for out-of-range.
        }
        throw new \InvalidArgumentException(
            sprintf('Worker option "%s" must be an integer or canonical integer string', $key)
        );
    }

    /**
     * Resolve a strict decimal option (finite int/float or canonical numeric string).
     *
     * @param array<string, mixed> $options Raw worker options
     * @param string $key Option key
     * @param float $default Default when absent
     */
    private static function decimalOption(array $options, string $key, float $default): float
    {
        if (!array_key_exists($key, $options)) {
            return $default;
        }
        $value = $options[$key];
        if (is_int($value) || is_float($value)) {
            if (!is_finite((float) $value)) {
                throw new \InvalidArgumentException(sprintf('Worker option "%s" must be finite', $key));
            }
            return (float) $value;
        }
        if (is_string($value) && preg_match('/^(0|[1-9][0-9]*)(\.[0-9]+)?$/', $value) === 1) {
            $parsed = (float) $value;
            if (is_finite($parsed)) {
                return $parsed;
            }
        }
        throw new \InvalidArgumentException(
            sprintf('Worker option "%s" must be a finite number or canonical numeric string', $key)
        );
    }

    /**
     * Resolve a strict boolean option (actual booleans only).
     *
     * @param array<string, mixed> $options Raw worker options
     * @param string $key Option key
     * @param bool $default Default when absent
     */
    private static function booleanOption(array $options, string $key, bool $default): bool
    {
        if (!array_key_exists($key, $options)) {
            return $default;
        }
        $value = $options[$key];
        if (!is_bool($value)) {
            throw new \InvalidArgumentException(sprintf('Worker option "%s" must be a boolean', $key));
        }
        return $value;
    }

    /**
     * Validate clock option strictly.
     *
     * @param array<string, mixed> $options Raw worker options
     * @return null Null when absent
     */
    private static function strictClock(array $options): null
    {
        if (array_key_exists('clock', $options) && $options['clock'] !== null) {
            throw new \InvalidArgumentException('Worker clock must be a ClockInterface instance or null');
        }
        return null;
    }

    /**
     * Validate listener option strictly.
     *
     * @param array<string, mixed> $options Raw worker options
     * @return callable|null Validated listener
     */
    private static function strictListener(array $options): mixed
    {
        $listener = $options['event_listener'] ?? null;
        if ($listener !== null && !is_callable($listener)) {
            throw new \InvalidArgumentException('Worker event listener must be callable or null');
        }
        return $listener;
    }

    /**
     * Validate the delayed-job promotion limit.
     *
     * @param int $promoteLimit Maximum delayed jobs promoted per pass
     */
    private static function assertPromoteLimit(int $promoteLimit): void
    {
        if ($promoteLimit < 1) {
            throw new \InvalidArgumentException('Worker promote limit must be positive');
        }
    }
}
