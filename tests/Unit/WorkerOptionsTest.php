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

        $this->assertSame(2, $options->pollTimeout);
        $this->assertSame(30, $options->stuckJobTtl);
    }

    public function testFromArrayParsesPromoteLimit(): void
    {
        $this->assertSame(100, WorkerOptions::fromArray([])->promoteLimit);
        $this->assertSame(250, WorkerOptions::fromArray(['promote_limit' => '250'])->promoteLimit);
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
}
