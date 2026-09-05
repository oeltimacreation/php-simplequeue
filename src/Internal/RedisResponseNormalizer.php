<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Internal;

use Oeltima\SimpleQueue\Exception\QueueException;

/**
 * Normalizes untyped Redis command responses at the Predis boundary.
 *
 * @internal
 */
final class RedisResponseNormalizer
{
    /**
     * Normalize a dequeued notification and discard malformed backend state.
     *
     * @param string $queue Queue containing the notification
     * @param mixed $result Raw Redis command result
     * @param callable(string, string): void $discardMalformed Removes the malformed processing notification
     * @return int|null Positive job ID, or null when the queue is empty
     * @throws QueueException When Redis returns a malformed job ID
     */
    public static function dequeuedJobId(string $queue, mixed $result, callable $discardMalformed): ?int
    {
        if (self::isEmptyDequeueResult($result)) {
            return null;
        }
        if (is_string($result) && self::isValidJobId($result)) {
            return (int) $result;
        }

        $value = is_scalar($result) ? (string) $result : '';
        $discardMalformed($queue, $value);
        throw new QueueException('Redis returned a malformed queue job ID');
    }

    private static function isEmptyDequeueResult(mixed $result): bool
    {
        return in_array($result, [null, false, ''], true);
    }

    /**
     * Normalize an integer-like Redis script response.
     *
     * Accepts only canonical non-negative integers within PHP range;
     * malformed or negative responses raise QueueException.
     *
     * @param mixed $result Raw Redis command result
     * @return int Integer result
     */
    public static function integer(mixed $result): int
    {
        if (is_int($result)) {
            return self::nonNegativeInteger($result);
        }
        if (is_string($result)) {
            return self::integerString($result);
        }
        throw new QueueException('Redis returned a malformed integer response');
    }

    private static function nonNegativeInteger(int $result): int
    {
        if ($result < 0) {
            throw new QueueException('Redis returned a malformed integer response');
        }
        return $result;
    }

    private static function integerString(string $result): int
    {
        if (preg_match('/^(0|[1-9][0-9]*)$/', $result) !== 1) {
            throw new QueueException('Redis returned a malformed integer response');
        }
        if (!self::fitsPhpInteger($result)) {
            throw new QueueException('Redis returned a malformed integer response');
        }
        return (int) $result;
    }

    private static function fitsPhpInteger(string $value): bool
    {
        $maximum = (string) PHP_INT_MAX;
        if (strlen($value) !== strlen($maximum)) {
            return strlen($value) < strlen($maximum);
        }
        return $value <= $maximum;
    }

    /**
     * Validate the canonical decimal representation of a positive PHP integer.
     *
     * @param string $value Raw Redis list member
     * @return bool True when the value is a safe positive job ID
     */
    public static function isValidJobId(string $value): bool
    {
        if (preg_match('/^[1-9][0-9]*$/', $value) !== 1) {
            return false;
        }
        return self::fitsPhpInteger($value);
    }
}
