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
        if ($result === null || $result === false || $result === '') {
            return null;
        }
        if (is_string($result) && self::isValidJobId($result)) {
            return (int) $result;
        }

        $value = is_scalar($result) ? (string) $result : '';
        $discardMalformed($queue, $value);
        throw new QueueException('Redis returned a malformed queue job ID');
    }

    /**
     * Normalize an integer-like Redis script response.
     *
     * @param mixed $result Raw Redis command result
     * @return int Integer result, or zero for an unexpected response
     */
    public static function integer(mixed $result): int
    {
        return is_int($result) ? $result : (is_numeric($result) ? (int) $result : 0);
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
