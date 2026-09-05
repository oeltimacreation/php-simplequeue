<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Internal;

use Oeltima\SimpleQueue\Contract\JobStatus;

/**
 * Side-effect-free selection predicates for the in-memory storage backend.
 *
 * @internal
 * @phpstan-type CandidateJob array{
 *     status: JobStatus,
 *     queue: string,
 *     available_at: string,
 *     locked_at: string|null
 * }
 */
final class InMemoryJobSelector
{
    /**
     * @param CandidateJob $job
     * @param array{queue: string, now: string} $window
     */
    public static function isAvailable(array $job, array $window): bool
    {
        if ($job['status'] !== JobStatus::Pending) {
            return false;
        }
        if ($job['queue'] !== $window['queue']) {
            return false;
        }
        return $job['available_at'] <= $window['now'];
    }

    /**
     * @param CandidateJob $job
     * @param array{id: int, availableAt: string|null, candidateId: int|null} $candidate
     */
    public static function isBetter(array $job, array $candidate): bool
    {
        if ($candidate['availableAt'] === null) {
            return true;
        }
        if ($job['available_at'] !== $candidate['availableAt']) {
            return $job['available_at'] < $candidate['availableAt'];
        }
        return $candidate['id'] < (int) $candidate['candidateId'];
    }

    /**
     * @param CandidateJob $job
     * @param array{queue: string, threshold: string} $window
     */
    public static function isStale(array $job, array $window): bool
    {
        if ($job['queue'] !== $window['queue']) {
            return false;
        }
        if ($job['status'] !== JobStatus::Running) {
            return false;
        }
        if ($job['locked_at'] === null) {
            return true;
        }
        return $job['locked_at'] < $window['threshold'];
    }

    /**
     * @param CandidateJob $job
     * @param array{id: int, queue: string, afterId: int|null} $cursor
     */
    public static function isPendingAfter(array $job, array $cursor): bool
    {
        if ($job['status'] !== JobStatus::Pending) {
            return false;
        }
        if ($job['queue'] !== $cursor['queue']) {
            return false;
        }
        if ($cursor['afterId'] === null) {
            return true;
        }
        return $cursor['id'] > $cursor['afterId'];
    }
}
