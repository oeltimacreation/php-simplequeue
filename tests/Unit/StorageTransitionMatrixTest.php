<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Unit;

use Oeltima\SimpleQueue\Storage\InMemoryJobStorage;
use Oeltima\SimpleQueue\Tests\Support\FrozenClock;
use Oeltima\SimpleQueue\Tests\Support\SqliteFixture;
use Oeltima\SimpleQueue\Tests\Support\StorageTransitionMatrix;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Data-driven storage transition suite (in-memory + SQLite locally;
 * MySQL/PostgreSQL in service CI via the same matrix).
 */
final class StorageTransitionMatrixTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function backends(): iterable
    {
        yield 'in-memory' => ['memory'];
        yield 'SQLite PDO' => ['pdo'];
    }

    #[DataProvider('backends')]
    public function testCreateClaimRetryTerminalReleaseStaleOwnership(string $backend): void
    {
        $clock = new FrozenClock();
        $storage = $backend === 'memory' ? new InMemoryJobStorage($clock) : SqliteFixture::createStorage('background_jobs', $clock);
        StorageTransitionMatrix::assertCreate($storage);
        StorageTransitionMatrix::assertClaim($storage);
        StorageTransitionMatrix::assertProgressAndCompletion($storage);
        StorageTransitionMatrix::assertRetry($storage);
        StorageTransitionMatrix::assertTerminalFailure($storage);
        StorageTransitionMatrix::assertGracefulRelease($storage);
        StorageTransitionMatrix::assertAdministration($storage);
        StorageTransitionMatrix::assertOwnership($storage);

        $clock2 = new FrozenClock();
        $storage2 = $backend === 'memory' ? new InMemoryJobStorage($clock2) : SqliteFixture::createStorage('background_jobs', $clock2);
        StorageTransitionMatrix::assertStaleParity($storage2, $clock2);

        $clock3 = new FrozenClock();
        $storage3 = $backend === 'memory' ? new InMemoryJobStorage($clock3) : SqliteFixture::createStorage('background_jobs', $clock3);
        StorageTransitionMatrix::assertScopedStaleParity($storage3, $clock3);
    }

    #[DataProvider('backends')]
    public function testInvalidBatchLeavesStateUnchanged(string $backend): void
    {
        $clock = new FrozenClock();
        $storage = $backend === 'memory' ? new InMemoryJobStorage($clock) : SqliteFixture::createStorage('background_jobs', $clock);
        $before = $storage->count();
        try {
            $storage->createJobs([
                ['type' => 'test.job', 'payload' => []],
                ['type' => '', 'payload' => []],
            ]);
            self::fail('Invalid batch must fail');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        self::assertSame($before, $storage->count());
    }
}
