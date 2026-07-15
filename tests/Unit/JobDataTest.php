<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Unit;

use Oeltima\SimpleQueue\Contract\JobData;
use Oeltima\SimpleQueue\Exception\SerializationException;
use PHPUnit\Framework\TestCase;

class JobDataTest extends TestCase
{
    public function testFromRawRejectsPayloadJsonThatDecodesToScalar(): void
    {
        $this->expectException(SerializationException::class);
        JobData::fromRaw([
            'id' => 1,
            'type' => 'test.job',
            'payload' => '"not-an-array"',
        ]);
    }

    public function testFromRawRejectsScalarPayload(): void
    {
        $this->expectException(SerializationException::class);
        JobData::fromRaw([
            'id' => 1,
            'type' => 'test.job',
            'payload' => 123,
        ]);
    }

    public function testFromRawMapsLeaseToken(): void
    {
        $job = JobData::fromRaw([
            'id' => 1,
            'type' => 'test.job',
            'lease_token' => 'lease-abc',
        ]);

        $this->assertSame('lease-abc', $job->leaseToken);
        $this->assertSame('lease-abc', $job->toArray()['lease_token']);
    }
}
