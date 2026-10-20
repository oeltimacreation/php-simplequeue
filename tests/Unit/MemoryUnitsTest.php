<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Unit;

use Oeltima\SimpleQueue\WorkerOptions;
use PHPUnit\Framework\TestCase;

final class MemoryUnitsTest extends TestCase
{
    public function testExplicitByteConversionRoundsUpAndPreservesDisabledLimit(): void
    {
        foreach ([0 => 0, 1 => 1, 1048576 => 1, 1048577 => 2, 268435456 => 256] as $bytes => $expected) {
            self::assertSame($expected, WorkerOptions::memoryMiBFromBytes($bytes));
        }
        $largest = WorkerOptions::memoryMiBFromBytes(PHP_INT_MAX);
        self::assertLessThan(PHP_INT_MAX, ($largest - 1) * 1048576);
        self::assertSame(256, (new WorkerOptions(memoryLimit: 256))->memoryLimit);
    }

    public function testNegativeBytesAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        WorkerOptions::memoryMiBFromBytes(-1);
    }
}
