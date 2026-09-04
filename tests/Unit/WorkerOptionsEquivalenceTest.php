<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Unit;

use Oeltima\SimpleQueue\SystemSleeper;
use Oeltima\SimpleQueue\Tests\Support\FrozenClock;
use Oeltima\SimpleQueue\WorkerOptions;
use PHPUnit\Framework\TestCase;

/**
 * Option-equivalence: array and typed construction agree, including strict
 * numbers, booleans, clocks, sleepers, and lock modes.
 */
final class WorkerOptionsEquivalenceTest extends TestCase
{
    public function testArrayAndTypedDefaultsAgree(): void
    {
        $fromArray = WorkerOptions::fromArray([]);
        $typed = new WorkerOptions();
        self::assertSame($typed->pollTimeout, $fromArray->pollTimeout);
        self::assertSame($typed->lockingEnabled, $fromArray->lockingEnabled);
        self::assertNull($fromArray->lockFile);
        self::assertNull($fromArray->sleeper);
    }

    public function testCanonicalNumericStringsAccepted(): void
    {
        $options = WorkerOptions::fromArray(['poll_timeout' => '5', 'promote_interval' => '2.5']);
        self::assertSame(5, $options->pollTimeout);
        self::assertSame(2.5, $options->promoteInterval);
    }

    public function testMalformedNumbersRejected(): void
    {
        foreach (
            [
            ['poll_timeout' => ' 5'],
            ['poll_timeout' => '+5'],
            ['poll_timeout' => '05'],
            ['poll_timeout' => '5.0'],
            ['poll_timeout' => true],
            ['promote_interval' => 'NaN'],
            ['promote_interval' => INF],
            ] as $options
        ) {
            try {
                WorkerOptions::fromArray($options);
                self::fail('Malformed option must be rejected: ' . json_encode($options));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testStringFalseIsInvalidBoolean(): void
    {
        try {
            WorkerOptions::fromArray(['stop_when_empty' => 'false']);
            self::fail('String false must not cast to true');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testLockModes(): void
    {
        self::assertTrue(WorkerOptions::fromArray([])->lockingEnabled);
        self::assertFalse(WorkerOptions::fromArray(['lock_file' => null])->lockingEnabled);
        self::assertSame('/tmp/custom.lock', WorkerOptions::fromArray(['lock_file' => '/tmp/custom.lock'])->lockFile);
        self::assertFalse((new WorkerOptions(lockingEnabled: false))->lockingEnabled);
        try {
            WorkerOptions::fromArray(['lock_file' => '/tmp/x.lock', 'locking_enabled' => false]);
            self::fail('Disabled+custom conflict must be rejected');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testClocksAndSleepersValidated(): void
    {
        $clock = new FrozenClock();
        $sleeper = new SystemSleeper();
        $options = WorkerOptions::fromArray(['clock' => $clock, 'sleeper' => $sleeper]);
        self::assertSame($clock, $options->clock);
        self::assertSame($sleeper, $options->sleeper);
        try {
            WorkerOptions::fromArray(['clock' => new \stdClass()]);
            self::fail('Invalid clock must be rejected');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        try {
            WorkerOptions::fromArray(['sleeper' => new \stdClass()]);
            self::fail('Invalid sleeper must be rejected');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testMemoryLimitInterpretedAsMib(): void
    {
        $options = WorkerOptions::fromArray(['memory_limit' => 128]);
        self::assertSame(128, $options->memoryLimit);
    }
}
