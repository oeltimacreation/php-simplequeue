<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Unit;

use Oeltima\SimpleQueue\Internal\JobStorageRules;
use Oeltima\SimpleQueue\Tests\Support\FrozenClock;
use PHPUnit\Framework\TestCase;

final class JobStorageRulesTest extends TestCase
{
    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock();
    }

    public function testJobTypeValidationCoversAcceptedAndRejectedValues(): void
    {
        self::assertSame('mail.send', $this->definition(['type' => 'mail.send'])['type']);
        foreach ([null, 1, '', '   ', str_repeat('t', 256)] as $type) {
            $this->assertInvalidDefinition(['type' => $type]);
        }
    }

    public function testJobQueueValidationCoversDefaultAcceptedAndRejectedValues(): void
    {
        self::assertSame('default', $this->definition()['queue']);
        self::assertSame('mail', $this->definition(['queue' => 'mail'])['queue']);
        foreach ([1, '', '   ', str_repeat('q', 256)] as $queue) {
            $this->assertInvalidDefinition(['queue' => $queue]);
        }
    }

    public function testJobPayloadAndMaximumAttemptsValidation(): void
    {
        self::assertSame(['id' => 1], $this->definition(['payload' => ['id' => 1]])['payload']);
        self::assertSame(3, $this->definition()['maxAttempts']);
        self::assertSame(5, $this->definition(['maxAttempts' => 5])['maxAttempts']);
        foreach ([null, 'payload', 10] as $payload) {
            $this->assertInvalidDefinition(['payload' => $payload]);
        }
        foreach (['3', 0] as $maxAttempts) {
            $this->assertInvalidDefinition(['maxAttempts' => $maxAttempts]);
        }
    }

    public function testRequestIdValidationCoversOptionalAcceptedAndRejectedValues(): void
    {
        self::assertNull($this->definition()['requestId']);
        self::assertSame('request-1', $this->definition(['requestId' => 'request-1'])['requestId']);
        foreach ([1, '', '   ', str_repeat('r', 256)] as $requestId) {
            $this->assertInvalidDefinition(['requestId' => $requestId]);
        }
    }

    public function testSharedScalarValidationCoversBoundaries(): void
    {
        JobStorageRules::validateProgress(null);
        JobStorageRules::validateProgress(0);
        JobStorageRules::validateProgress(100);
        $this->assertInvalidCall(static fn () => JobStorageRules::validateProgress(-1));
        $this->assertInvalidCall(static fn () => JobStorageRules::validateProgress(101));
        $this->assertInvalidCall(static fn () => JobStorageRules::validateStaleRecovery(0, 1));
        $this->assertInvalidCall(static fn () => JobStorageRules::validateStaleRecovery(1, 0));
        self::assertSame('worker', JobStorageRules::validateWorkerId('worker'));
        $this->assertInvalidCall(static fn () => JobStorageRules::validateWorkerId(''));
        $this->assertInvalidCall(static fn () => JobStorageRules::validateWorkerId(str_repeat('w', 256)));
        self::assertSame('bounded', JobStorageRules::validateBoundedString('bounded', 'Value'));
        $this->assertInvalidCall(
            static fn () => JobStorageRules::validateBoundedString(str_repeat('v', 256), 'Value')
        );
    }

    public function testAvailableAtValidationRejectsUnsupportedValues(): void
    {
        self::assertSame($this->clock->now(), $this->definition()['availableAt']);
        self::assertSame($this->clock->now(), JobStorageRules::normalizeAvailableAt(null, $this->clock));
        $this->assertInvalidDefinition(['availableAt' => 'tomorrow']);
        $this->assertInvalidDefinition(['availableAt' => 0]);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function definition(array $overrides = []): array
    {
        return JobStorageRules::validateJobDefinition(
            array_merge(['type' => 'test.job', 'payload' => []], $overrides),
            $this->clock
        );
    }

    /** @param array<string, mixed> $overrides */
    private function assertInvalidDefinition(array $overrides): void
    {
        $this->assertInvalidCall(fn () => $this->definition($overrides));
    }

    /** @param callable(): mixed $operation */
    private function assertInvalidCall(callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected invalid input to be rejected');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
    }
}
