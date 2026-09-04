<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Unit;

use Oeltima\SimpleQueue\WorkerOptions;
use PHPUnit\Framework\TestCase;

final class WorkerOptionsTest extends TestCase
{
    public function testFromArrayAcceptsNumericStringEnvironmentValues(): void
    {
        $options = WorkerOptions::fromArray(['poll_timeout' => '2', 'stuck_job_ttl' => '30']);

        self::assertSame(2, $options->pollTimeout);
        self::assertSame(30, $options->stuckJobTtl);
    }

    public function testFromArrayParsesPromoteLimit(): void
    {
        self::assertSame(100, WorkerOptions::fromArray([])->promoteLimit);
        self::assertSame(250, WorkerOptions::fromArray(['promote_limit' => '250'])->promoteLimit);
    }

    public function testRejectsNonPositivePromoteLimit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new WorkerOptions(promoteLimit: 0);
    }

    public function testRejectsUnsafeRetryAndTtlCombinations(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new WorkerOptions(stuckJobTtl: 0, retryBaseDelay: 10, retryMaxDelay: 1);
    }

    public function testTypedIntervalsMustBeFinite(): void
    {
        foreach ([NAN, INF, -INF] as $interval) {
            try {
                new WorkerOptions(promoteInterval: $interval);
                self::fail('Non-finite promote interval must fail');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
            try {
                new WorkerOptions(recoveryInterval: $interval);
                self::fail('Non-finite recovery interval must fail');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
