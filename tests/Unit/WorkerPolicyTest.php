<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Unit;

use Oeltima\SimpleQueue\Internal\OwnershipOutcome;
use Oeltima\SimpleQueue\Internal\RetryDecision;
use Oeltima\SimpleQueue\Internal\WorkerPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WorkerPolicyTest extends TestCase
{
    /**
     * @return iterable<string, array{\Throwable, bool}>
     */
    public static function infrastructureFailures(): iterable
    {
        yield 'PDO' => [new \PDOException('connection lost'), true];
        yield 'Predis' => [new \Predis\ClientException('connection lost'), true];
        yield 'handler failure' => [new \RuntimeException('handler failed'), false];
    }

    #[DataProvider('infrastructureFailures')]
    public function testClassifiesInfrastructureFailures(\Throwable $exception, bool $expected): void
    {
        $policy = new WorkerPolicy(2, 30);

        $this->assertSame($expected, $policy->isInfrastructureException($exception));
    }

    public function testCalculatesCappedRetryAndBackoffDelays(): void
    {
        $policy = new WorkerPolicy(2, 10);

        $this->assertSame(4, $policy->retryDelay(2));
        $this->assertSame(10, $policy->retryDelay(4));
        $this->assertSame(8, $policy->backoffDelay(3));
    }

    public function testDecidesRetryEligibilityWithoutStorageIo(): void
    {
        $policy = new WorkerPolicy(2, 30);

        $this->assertSame(RetryDecision::Retry, $policy->retryDecision(2, 3));
        $this->assertSame(RetryDecision::Fail, $policy->retryDecision(3, 3));
    }

    public function testTreatsRejectedFencedTransitionAsLostOwnership(): void
    {
        $policy = new WorkerPolicy(2, 30);

        $this->assertSame(OwnershipOutcome::Lost, $policy->ownershipOutcome(false));
        $this->assertSame(OwnershipOutcome::Owned, $policy->ownershipOutcome(true));
    }
}
