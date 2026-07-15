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

    public function testRejectsUnsafeRetryAndTtlCombinations(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new WorkerOptions(stuckJobTtl: 0, retryBaseDelay: 10, retryMaxDelay: 1);
    }
}
