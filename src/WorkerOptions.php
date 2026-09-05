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
        self::validateTiming($pollTimeout, $stuckJobTtl, $retryBaseDelay, $retryMaxDelay);
        self::validateLimits($maxJobs, $maxTime, $memoryLimit);
        self::validateIntervals($promoteInterval, $recoveryInterval);
        self::assertPromoteLimit($promoteLimit);
        self::validateEventListener($eventListener);
        self::validateLocking($lockingEnabled, $lockFile);
    }

    private static function validateTiming(
        int $pollTimeout,
        int $stuckJobTtl,
        int $retryBaseDelay,
        int $retryMaxDelay
    ): void {
        self::assertNonNegative($pollTimeout, 'Worker poll timeout');
        self::assertPositive($stuckJobTtl, 'Worker stuck-job TTL');
        self::assertNonNegative($retryBaseDelay, 'Worker retry base delay');
        if ($retryMaxDelay < $retryBaseDelay) {
            throw new \InvalidArgumentException('Worker timeout, TTL, and retry delay options are invalid');
        }
    }

    private static function validateLimits(
        int $maxJobs,
        int $maxTime,
        int $memoryLimit
    ): void {
        self::assertNonNegative($maxJobs, 'Worker maximum jobs');
        self::assertNonNegative($maxTime, 'Worker maximum time');
        self::assertNonNegative($memoryLimit, 'Worker memory limit');
    }

    private static function validateIntervals(float $promoteInterval, float $recoveryInterval): void
    {
        self::assertFiniteNonNegative($promoteInterval, 'Worker promotion interval');
        self::assertFiniteNonNegative($recoveryInterval, 'Worker recovery interval');
    }

    private static function assertNonNegative(int $value, string $field): void
    {
        if ($value < 0) {
            throw new \InvalidArgumentException(sprintf('%s must be non-negative', $field));
        }
    }

    private static function assertPositive(int $value, string $field): void
    {
        if ($value < 1) {
            throw new \InvalidArgumentException(sprintf('%s must be positive', $field));
        }
    }

    private static function assertFiniteNonNegative(float $value, string $field): void
    {
        if (!is_finite($value)) {
            throw new \InvalidArgumentException(sprintf('%s must be finite', $field));
        }
        if ($value < 0) {
            throw new \InvalidArgumentException(sprintf('%s must be non-negative', $field));
        }
    }

    private static function validateEventListener(mixed $eventListener): void
    {
        if ($eventListener !== null && !is_callable($eventListener)) {
            throw new \InvalidArgumentException('Worker event listener must be callable or null');
        }
    }

    private static function validateLocking(bool $lockingEnabled, ?string $lockFile): void
    {
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
        $lockFile = self::lockFile($options);
        $lockingEnabled = self::lockingEnabled($options, $lockFile);

        return new self(
            lockFile: $lockFile,
            pollTimeout: self::integerOption($options, 'poll_timeout', 5),
            stuckJobTtl: self::integerOption($options, 'stuck_job_ttl', 600),
            retryBaseDelay: self::integerOption($options, 'retry_base_delay', 2),
            retryMaxDelay: self::integerOption($options, 'retry_max_delay', 300),
            clock: self::clock($options),
            maxJobs: self::integerOption($options, 'max_jobs', 0),
            maxTime: self::integerOption($options, 'max_time', 0),
            memoryLimit: self::integerOption($options, 'memory_limit', 0),
            stopWhenEmpty: self::booleanOption($options, 'stop_when_empty', false),
            promoteInterval: self::decimalOption($options, 'promote_interval', 5.0),
            promoteLimit: self::integerOption($options, 'promote_limit', 100),
            recoveryInterval: self::decimalOption($options, 'recovery_interval', 60.0),
            eventListener: self::strictListener($options),
            lockingEnabled: $lockingEnabled,
            sleeper: self::sleeper($options)
        );
    }

    /** @param array<string, mixed> $options */
    private static function lockFile(array $options): ?string
    {
        if (!array_key_exists('lock_file', $options) || $options['lock_file'] === null) {
            return null;
        }
        $lockFile = $options['lock_file'];
        if (!is_string($lockFile)) {
            throw new \InvalidArgumentException('Worker lock_file must be a non-empty string or null');
        }
        if (trim($lockFile) === '') {
            throw new \InvalidArgumentException('Worker custom lock path must not be empty');
        }
        return $lockFile;
    }

    /** @param array<string, mixed> $options */
    private static function lockingEnabled(array $options, ?string $lockFile): bool
    {
        $enabled = !array_key_exists('lock_file', $options) || $options['lock_file'] !== null;
        if (array_key_exists('locking_enabled', $options)) {
            $enabled = self::booleanValue($options['locking_enabled'], 'locking_enabled');
        }
        self::validateLocking($enabled, $lockFile);
        return $enabled;
    }

    private static function booleanValue(mixed $value, string $key): bool
    {
        if (!is_bool($value)) {
            throw new \InvalidArgumentException(sprintf('Worker %s must be a boolean', $key));
        }
        return $value;
    }

    /** @param array<string, mixed> $options */
    private static function clock(array $options): ?ClockInterface
    {
        $clock = $options['clock'] ?? null;
        if ($clock !== null && !$clock instanceof ClockInterface) {
            throw new \InvalidArgumentException('Worker clock must be a ClockInterface instance or null');
        }
        return $clock;
    }

    /** @param array<string, mixed> $options */
    private static function sleeper(array $options): ?SleeperInterface
    {
        $sleeper = $options['sleeper'] ?? null;
        if ($sleeper !== null && !$sleeper instanceof SleeperInterface) {
            throw new \InvalidArgumentException('Worker sleeper must be a SleeperInterface instance or null');
        }
        return $sleeper;
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
        if (is_int($value)) {
            return (float) $value;
        }
        if (is_float($value)) {
            return self::finiteDecimal($value, $key);
        }
        if (is_string($value)) {
            return self::decimalString($value, $key);
        }
        throw new \InvalidArgumentException(
            sprintf('Worker option "%s" must be a finite number or canonical numeric string', $key)
        );
    }

    private static function finiteDecimal(float $value, string $key): float
    {
        if (!is_finite($value)) {
            throw new \InvalidArgumentException(sprintf('Worker option "%s" must be finite', $key));
        }
        return $value;
    }

    private static function decimalString(string $value, string $key): float
    {
        if (preg_match('/^(0|[1-9][0-9]*)(\.[0-9]+)?$/', $value) !== 1) {
            throw new \InvalidArgumentException(
                sprintf('Worker option "%s" must be a finite number or canonical numeric string', $key)
            );
        }
        return self::finiteDecimal((float) $value, $key);
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
        return self::booleanValue($options[$key], sprintf('option "%s"', $key));
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
