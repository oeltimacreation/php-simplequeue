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

    public function testFromRawRejectsInvalidJsonPayloadString(): void
    {
        $this->expectException(SerializationException::class);
        $this->expectExceptionMessage('Stored job payload contains invalid JSON');

        JobData::fromRaw([
            'id' => 1,
            'type' => 'test.job',
            'payload' => '{invalid-json',
        ]);
    }

    public function testFromRawRejectsInvalidJsonResultString(): void
    {
        $this->expectException(SerializationException::class);
        $this->expectExceptionMessage('Stored job result contains invalid JSON');

        JobData::fromRaw([
            'id' => 1,
            'type' => 'test.job',
            'result' => '{invalid-result-json',
        ]);
    }

    public function testNormalizeAvailableAtConvertsNonUtcTimezoneToUtcString(): void
    {
        $clock = new \Oeltima\SimpleQueue\SystemClock();
        $dt = new \DateTimeImmutable('2026-08-08 12:00:00', new \DateTimeZone('Asia/Tokyo')); // UTC 03:00:00

        $utcString = \Oeltima\SimpleQueue\Internal\JobStorageRules::normalizeAvailableAt($dt, $clock);

        $this->assertSame('2026-08-08 03:00:00', $utcString);
    }

    public function testEncodeJsonThrowsSerializationExceptionOnUnencodableData(): void
    {
        $this->expectException(SerializationException::class);
        $this->expectExceptionMessage('Unable to encode test data as JSON');

        \Oeltima\SimpleQueue\Internal\JobStorageRules::encodeJson(NAN, 'test data');
    }
}
