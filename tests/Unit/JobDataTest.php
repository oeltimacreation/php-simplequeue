<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Unit;

use Oeltima\SimpleQueue\Contract\JobData;
use Oeltima\SimpleQueue\Exception\SerializationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class JobDataTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     */
    #[DataProvider('invalidRawJobDataProvider')]
    public function testFromRawRejectsInvalidData(array $overrides, ?string $expectedMessage = null): void
    {
        $this->expectException(SerializationException::class);
        if ($expectedMessage !== null) {
            $this->expectExceptionMessage($expectedMessage);
        }

        $raw = array_merge(['id' => 1, 'type' => 'test.job'], $overrides);
        JobData::fromRaw($raw);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1?: string|null}>
     */
    public static function invalidRawJobDataProvider(): array
    {
        return [
            'payload json scalar' => [['payload' => '"not-an-array"']],
            'scalar payload' => [['payload' => 123]],
            'invalid json payload' => [['payload' => '{invalid-json'], 'Stored job payload contains invalid JSON'],
            'invalid json result' => [['result' => '{invalid-result-json'], 'Stored job result contains invalid JSON'],
        ];
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
