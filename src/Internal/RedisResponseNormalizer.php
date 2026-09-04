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
        if (is_int($result) && $result >= 0) {
            return $result;
        }
        if (is_string($result) && preg_match('/^(0|[1-9][0-9]*)$/', $result) === 1) {
            if (strlen($result) < strlen((string) PHP_INT_MAX) || $result <= (string) PHP_INT_MAX) {
                return (int) $result;
            }
        }
        throw new QueueException('Redis returned a malformed integer response');
    }

    /**
     * Validate the canonical decimal representation of a positive PHP integer.
     *
     * @param string $value Raw Redis list member
     * @return bool True when the value is a safe positive job ID
     */
    public static function isValidJobId(string $value): bool
    {
        return preg_match('/^[1-9][0-9]*$/', $value) === 1
            && (strlen($value) < strlen((string) PHP_INT_MAX)
                || (strlen($value) === strlen((string) PHP_INT_MAX) && $value <= (string) PHP_INT_MAX));
    }
}
