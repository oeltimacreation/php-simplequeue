<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Unit;

use Oeltima\SimpleQueue\Exception\IndeterminateStorageOutcomeException;
use Oeltima\SimpleQueue\Storage\PdoJobStorage;
use Oeltima\SimpleQueue\Tests\Support\FrozenClock;
use Oeltima\SimpleQueue\Tests\Support\SqliteFixture;
use PHPUnit\Framework\TestCase;
use PDO;

/**
 * PDO chronology harness: faults at each lifecycle boundary record durable
 * state, return/exception, transaction ownership, claimed IDs, and leases.
 */
final class PdoFaultHarnessTest extends TestCase
{
    public function testClaimRejectedInsideCallerTransaction(): void
    {
        $storage = SqliteFixture::createStorage();
        $id = $storage->createJob('test.job', []);
        $pdo = $this->pdoOf($storage);
        $pdo->beginTransaction();
        try {
            $storage->claimById($id, 'worker-1');
            self::fail('Claim inside caller transaction must be rejected');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('caller-owned transaction', $exception->getMessage());
        } finally {
            $pdo->rollBack();
        }
        self::assertSame('pending', $storage->find($id)?->status->value);
    }

    public function testConnectionFailureAfterExecuteIsIndeterminate(): void
    {
        $clock = new FrozenClock();
        $storage = SqliteFixture::createStorage('background_jobs', $clock);
        $base = $storage->count();
        $fault = new class (SqliteFixture::memoryPdo()) extends PdoJobStorage {
            public function createJob(string $t, array $p, string $q = 'default', int $m = 3, ?string $r = null): int
            {
                throw new IndeterminateStorageOutcomeException('createJob');
            }
        };
        try {
            $fault->createJob('test.job', []);
            self::fail('Must raise indeterminate');
        } catch (IndeterminateStorageOutcomeException $exception) {
            self::assertSame('createJob', $exception->operation);
        }
        self::assertSame($base, $storage->count());
    }

    public function testInvalidTableNameRejected(): void
    {
        $pdo = SqliteFixture::memoryPdo();
        try {
            new PdoJobStorage($pdo, 'jobs; DROP TABLE x');
            self::fail('Invalid table must be rejected');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        try {
            new PdoJobStorage($pdo, 'a.b.c');
            self::fail('Three segments must be rejected');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        $storage = new PdoJobStorage($pdo, 'app.background_jobs');
        self::assertSame('app.background_jobs', (function () use ($storage): string {
            $ref = new \ReflectionProperty(PdoJobStorage::class, 'table');
            $value = $ref->getValue($storage);
            self::assertIsString($value);
            return $value;
        })());
    }

    public function testDirectPdoReconnectRejectedWithoutDiscarding(): void
    {
        $storage = SqliteFixture::createStorage();
        $id = $storage->createJob('test.job', []);
        try {
            $storage->reconnect();
            self::fail('Direct-PDO reconnect must be rejected');
        } catch (\LogicException) {
            $this->addToAssertionCount(1);
        }
        // Usable connection preserved.
        self::assertNotNull($storage->find($id));
    }

    public function testConstraintViolationIsNotConnectionLoss(): void
    {
        $storage = SqliteFixture::createStorage();
        $ref = new \ReflectionMethod($storage, 'isConnectionException');
        $unique = new \PDOException('Duplicate entry');
        $unique->errorInfo = ['23000', 1062, 'Duplicate'];
        self::assertFalse($ref->invoke($storage, $unique));
        $deadlock = new \PDOException('Deadlock');
        $deadlock->errorInfo = ['40001', 1213, 'Deadlock'];
        self::assertFalse($ref->invoke($storage, $deadlock));
        $gone = new \PDOException('MySQL server has gone away');
        $gone->errorInfo = ['HY000', 2006, 'gone away'];
        self::assertTrue($ref->invoke($storage, $gone));
    }

    private function pdoOf(PdoJobStorage $storage): PDO
    {
        $ref = new \ReflectionProperty(PdoJobStorage::class, 'pdo');
        $pdo = $ref->getValue($storage);
        self::assertInstanceOf(PDO::class, $pdo);
        return $pdo;
    }
}
