<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Unit;

use Oeltima\SimpleQueue\Contract\JobData;
use PHPUnit\Framework\TestCase;

class JobDataTest extends TestCase
{
    public function testFromRawFallsBackToEmptyPayloadWhenJsonDecodesToScalar(): void
    {
        $job = JobData::fromRaw([
            'id' => 1,
            'type' => 'test.job',
            'payload' => '"not-an-array"',
        ]);

        $this->assertSame([], $job->payload);
    }

    public function testFromRawFallsBackToEmptyPayloadWhenPayloadIsScalar(): void
    {
        $job = JobData::fromRaw([
            'id' => 1,
            'type' => 'test.job',
            'payload' => 123,
        ]);

        $this->assertSame([], $job->payload);
    }
}
