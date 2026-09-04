<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Unit;

use Oeltima\SimpleQueue\Internal\RetryDecision;
use Oeltima\SimpleQueue\Internal\WorkerPolicy;
use PHPUnit\Framework\TestCase;

final class WorkerPolicyTest extends TestCase
{
    public function testCalculatesCappedRetryAndBackoffDelays(): void
    {
        $policy = new WorkerPolicy(2, 10);

        self::assertSame(4, $policy->retryDelay(2));
        self::assertSame(10, $policy->retryDelay(4));
        self::assertSame(8, $policy->backoffDelay(3));
    }

    public function testLargeConsecutiveFailureCountCannotOverflowBackoff(): void
    {
        $policy = new WorkerPolicy(2, 30);

        self::assertSame(30, $policy->backoffDelay(10_000));
    }

    public function testDecidesRetryEligibilityWithoutStorageIo(): void
    {
        $policy = new WorkerPolicy(2, 30);

        self::assertSame(RetryDecision::Retry, $policy->retryDecision(2, 3));
        self::assertSame(RetryDecision::Fail, $policy->retryDecision(3, 3));
    }

    public function testTreatsRejectedFencedTransitionAsLostOwnership(): void
    {
        $policy = new WorkerPolicy(2, 30);

        self::assertTrue($policy->lostOwnership(false));
        self::assertFalse($policy->lostOwnership(true));
    }
}
