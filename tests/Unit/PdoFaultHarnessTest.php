<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Unit;

use Oeltima\SimpleQueue\Exception\IndeterminateStorageOutcomeException;
use Oeltima\SimpleQueue\Storage\PdoJobStorage;
use Oeltima\SimpleQueue\Tests\DbHelper;
use Oeltima\SimpleQueue\Tests\Support\SqliteFixture;
use PHPUnit\Framework\TestCase;
use PDO;
use PDOException;
use PDOStatement;

final class PdoFaultProbe
{
    public string $preparingSql = '';

    /** @var list<string> */
    public array $events = [];

    /** @var list<array{phase: string, contains: string, occurrence: int, seen: int, kind: string}> */
    private array $faults = [];

    public function arm(
        string $phase,
        string $contains = '',
        int $occurrence = 1,
        string $kind = 'connection'
    ): void {
        $this->events = [];
        $this->faults[] = [
            'phase' => $phase,
            'contains' => strtoupper($contains),
            'occurrence' => $occurrence,
            'seen' => 0,
            'kind' => $kind,
        ];
    }

    public function reach(string $phase, string $sql = ''): void
    {
        $this->events[] = $phase;
        foreach ($this->faults as &$fault) {
            if ($fault['phase'] !== $phase || !str_contains(strtoupper($sql), $fault['contains'])) {
                continue;
            }
            $fault['seen']++;
            if ($fault['seen'] !== $fault['occurrence']) {
                continue;
            }
            $kind = $fault['kind'];
            if ($kind === 'constraint') {
                $exception = new PDOException('Injected constraint failure');
                $exception->errorInfo = ['23000', 19, 'Injected constraint failure'];
                throw $exception;
            }
            $exception = new PDOException('Injected connection loss', 2006);
            $exception->errorInfo = ['08006', 2006, 'Injected connection loss'];
            throw $exception;
        }
        unset($fault);
    }
}

class PdoFaultStatement extends PDOStatement
{
    private string $sql;

    protected function __construct(private readonly PdoFaultProbe $probe)
    {
        $this->sql = $probe->preparingSql;
    }

    /** @param array<array-key, mixed>|null $params */
    public function execute(?array $params = null): bool
    {
        $this->probe->reach('execute_before', $this->sql);
        $result = parent::execute($params);
        $this->probe->reach('execute_after', $this->sql);
        return $result;
    }

    public function fetch(
        int $mode = PDO::FETCH_DEFAULT,
        int $cursorOrientation = PDO::FETCH_ORI_NEXT,
        int $cursorOffset = 0
    ): mixed {
        $this->probe->reach('result_read', $this->sql);
        return parent::fetch($mode, $cursorOrientation, $cursorOffset);
    }

    /** @return array<array-key, mixed> */
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        $this->probe->reach('result_read', $this->sql);
        return parent::fetchAll($mode, ...$args);
    }

    public function fetchColumn(int $column = 0): mixed
    {
        $this->probe->reach('result_read', $this->sql);
        return parent::fetchColumn($column);
    }
}

class PdoFaultConnection extends PDO
{
    public function __construct(string $path, private readonly PdoFaultProbe $probe)
    {
        parent::__construct('sqlite:' . $path);
        $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, [PdoFaultStatement::class, [$probe]]);
    }

    /** @param array<array-key, mixed> $options */
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->probe->reach('prepare', $query);
        $this->probe->preparingSql = $query;
        try {
            return parent::prepare($query, $options);
        } finally {
            $this->probe->preparingSql = '';
        }
    }

    public function beginTransaction(): bool
    {
        $this->probe->reach('begin_before');
        $result = parent::beginTransaction();
        $this->probe->reach('begin_after');
        return $result;
    }

    public function commit(): bool
    {
        $this->probe->reach('commit_before');
        $result = parent::commit();
        $this->probe->reach('commit_after');
        return $result;
    }

    public function rollBack(): bool
    {
        $this->probe->reach('rollback_before');
        $result = parent::rollBack();
        $this->probe->reach('rollback_after');
        return $result;
    }

    public function exec(string $statement): int|false
    {
        $operation = match (true) {
            str_starts_with(strtoupper(trim($statement)), 'BEGIN') => 'begin',
            str_starts_with(strtoupper(trim($statement)), 'COMMIT') => 'commit',
            str_starts_with(strtoupper(trim($statement)), 'ROLLBACK') => 'rollback',
            default => 'exec',
        };
        $this->probe->reach($operation . '_before', $statement);
        $result = parent::exec($statement);
        $this->probe->reach($operation . '_after', $statement);
        return $result;
    }
}

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

    public function testConnectionAcquisitionRetriesBeforeMutationBegins(): void
    {
        $path = $this->databasePath();
        $this->initializeDatabase($path);
        $calls = 0;
        $storage = new PdoJobStorage(function () use (&$calls, $path): PDO {
            $calls++;
            if ($calls === 1) {
                $exception = new PDOException('Injected acquisition failure', 2006);
                $exception->errorInfo = ['08006', 2006, 'Injected acquisition failure'];
                throw $exception;
            }
            return SqliteFixture::filePdo($path);
        });

        try {
            self::assertSame(1, $storage->createJob('test.job', []));
            self::assertSame(2, $calls);
            self::assertSame(1, $storage->count());
        } finally {
            unlink($path);
        }
    }

    public function testPrepareFailureIsIndeterminateAndNeverReplayed(): void
    {
        $path = $this->databasePath();
        $this->initializeDatabase($path);
        $probe = new PdoFaultProbe();
        $probe->arm('prepare', 'INSERT INTO');
        $storage = new PdoJobStorage(new PdoFaultConnection($path, $probe));

        try {
            $storage->createJob('test.job', []);
            self::fail('Mutation prepare fault must be indeterminate');
        } catch (IndeterminateStorageOutcomeException $exception) {
            self::assertSame('createJob', $exception->operation);
            self::assertSame(['prepare'], $probe->events);
            self::assertSame(0, $this->rowCount($path));
        } finally {
            unlink($path);
        }
    }

    public function testPostExecuteFailureLeavesOneDurableWriteAndIsNotReplayed(): void
    {
        $path = $this->databasePath();
        $this->initializeDatabase($path);
        $probe = new PdoFaultProbe();
        $probe->arm('execute_after', 'INSERT INTO');
        $storage = new PdoJobStorage(new PdoFaultConnection($path, $probe));

        try {
            $storage->createJob('test.job', []);
            self::fail('Post-execute connection loss must be indeterminate');
        } catch (IndeterminateStorageOutcomeException $exception) {
            self::assertSame('createJob', $exception->operation);
            self::assertSame(1, $this->rowCount($path));
            self::assertSame(['prepare', 'execute_before', 'execute_after'], $probe->events);
        } finally {
            unlink($path);
        }
    }

    public function testAmbiguousIdempotentInsertResolvesAsExistingRatherThanCreated(): void
    {
        $path = $this->databasePath();
        $this->initializeDatabase($path);
        $probe = new PdoFaultProbe();
        $faulting = new PdoFaultConnection($path, $probe);
        $connections = 0;
        $storage = new PdoJobStorage(function () use (&$connections, $faulting, $path): PDO {
            $connections++;
            return $connections === 1 ? $faulting : SqliteFixture::filePdo($path);
        });
        $probe->arm('execute_after', 'INSERT INTO');

        try {
            $result = $storage->createIdempotentJob('test.job', [], 'request-1', 'default', 3);
            self::assertFalse($result->created);
            self::assertSame(1, $result->jobId);
            self::assertSame(1, $this->rowCount($path));
            self::assertSame(2, $connections);
        } finally {
            unlink($path);
        }
    }

    public function testReadResultFaultRetriesWholeReadOutsideCallerTransaction(): void
    {
        $path = $this->databasePath();
        $this->initializeDatabase($path);
        $seed = new PdoJobStorage(SqliteFixture::filePdo($path));
        $id = $seed->createJob('test.job', []);
        $probe = new PdoFaultProbe();
        $faulting = new PdoFaultConnection($path, $probe);
        $connections = 0;
        $storage = new PdoJobStorage(function () use (&$connections, $faulting, $path): PDO {
            $connections++;
            return $connections === 1 ? $faulting : SqliteFixture::filePdo($path);
        });
        $probe->arm('result_read', 'SELECT *');

        try {
            self::assertSame($id, $storage->find($id)?->id);
            self::assertSame(2, $connections);
        } finally {
            unlink($path);
        }
    }

    public function testReadResultFaultDoesNotRetryInsideCallerTransaction(): void
    {
        $path = $this->databasePath();
        $this->initializeDatabase($path);
        $seed = new PdoJobStorage(SqliteFixture::filePdo($path));
        $id = $seed->createJob('test.job', []);
        $probe = new PdoFaultProbe();
        $faulting = new PdoFaultConnection($path, $probe);
        $connections = 0;
        $storage = new PdoJobStorage(function () use (&$connections, $faulting, $path): PDO {
            $connections++;
            return $connections === 1 ? $faulting : SqliteFixture::filePdo($path);
        });
        self::assertSame($id, $storage->find($id)?->id);
        $faulting->beginTransaction();
        $probe->arm('result_read', 'SELECT *');

        try {
            $storage->find($id);
            self::fail('Caller-transaction read must not retry');
        } catch (PDOException) {
            self::assertSame(1, $connections);
            self::assertTrue($faulting->inTransaction());
        } finally {
            $faulting->rollBack();
            unlink($path);
        }
    }

    public function testClaimBeginFailureIsIndeterminateWithoutDurableClaim(): void
    {
        $path = $this->databasePath();
        $this->initializeDatabase($path);
        $seed = new PdoJobStorage(SqliteFixture::filePdo($path));
        $id = $seed->createJob('test.job', []);
        $probe = new PdoFaultProbe();
        $probe->arm('begin_before');
        $storage = new PdoJobStorage(new PdoFaultConnection($path, $probe));

        try {
            $storage->claimById($id, 'worker-1');
            self::fail('Claim begin fault must be indeterminate');
        } catch (IndeterminateStorageOutcomeException $exception) {
            self::assertSame('claimJob', $exception->operation);
            self::assertSame('pending', $seed->find($id)?->status->value);
        } finally {
            unlink($path);
        }
    }

    public function testClaimCommitFailureDoesNotReplayOrClaimSecondRow(): void
    {
        $path = $this->databasePath();
        $this->initializeDatabase($path);
        $seed = new PdoJobStorage(SqliteFixture::filePdo($path));
        $first = $seed->createJob('first.job', []);
        $second = $seed->createJob('second.job', []);
        $probe = new PdoFaultProbe();
        $probe->arm('commit_after');
        $storage = new PdoJobStorage(new PdoFaultConnection($path, $probe));

        try {
            $storage->claimNextAvailable('default', 'worker-1');
            self::fail('Post-commit claim fault must be indeterminate');
        } catch (IndeterminateStorageOutcomeException $exception) {
            self::assertSame('claimJob', $exception->operation);
            $firstJob = $seed->find($first);
            $secondJob = $seed->find($second);
            self::assertNotNull($firstJob);
            self::assertNotNull($secondJob);
            self::assertSame('running', $firstJob->status->value);
            self::assertSame('pending', $secondJob->status->value);
            self::assertSame('worker-1', $firstJob->lockedBy);
            self::assertNotNull($firstJob->leaseToken);
        } finally {
            unlink($path);
        }
    }

    public function testClaimResultReadFailureAfterCommitIsIndeterminate(): void
    {
        $path = $this->databasePath();
        $this->initializeDatabase($path);
        $seed = new PdoJobStorage(SqliteFixture::filePdo($path));
        $id = $seed->createJob('test.job', []);
        $probe = new PdoFaultProbe();
        $probe->arm('result_read', 'SELECT *', 2);
        $storage = new PdoJobStorage(new PdoFaultConnection($path, $probe));

        try {
            $storage->claimNextAvailable('default', 'worker-1');
            self::fail('Post-commit result fault must be indeterminate');
        } catch (IndeterminateStorageOutcomeException $exception) {
            self::assertSame('claimJob', $exception->operation);
            self::assertSame('running', $seed->find($id)?->status->value);
        } finally {
            unlink($path);
        }
    }

    public function testRollbackFaultIsRecordedAndOriginalKnownFailureIsPreserved(): void
    {
        $path = $this->databasePath();
        $this->initializeDatabase($path);
        $probe = new PdoFaultProbe();
        $probe->arm('execute_before', 'INSERT INTO', 2, 'constraint');
        $probe->arm('rollback_after');
        $storage = new PdoJobStorage(new PdoFaultConnection($path, $probe));
        $jobs = array_fill(0, 101, ['type' => 'test.job', 'payload' => []]);

        try {
            $storage->createJobs($jobs);
            self::fail('Injected batch failure must escape');
        } catch (PDOException $exception) {
            self::assertSame('23000', $exception->errorInfo[0] ?? null);
            self::assertContains('rollback_after', $probe->events);
            self::assertSame(0, $this->rowCount($path));
        } finally {
            unlink($path);
        }
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

    private function databasePath(): string
    {
        return sys_get_temp_dir() . '/simplequeue-pdo-fault-' . bin2hex(random_bytes(8)) . '.sqlite';
    }

    private function initializeDatabase(string $path): void
    {
        DbHelper::createSchema(SqliteFixture::filePdo($path));
    }

    private function rowCount(string $path): int
    {
        $statement = SqliteFixture::filePdo($path)->query('SELECT COUNT(*) FROM background_jobs');
        if (!$statement instanceof PDOStatement) {
            return -1;
        }
        $count = $statement->fetchColumn();
        return is_int($count) || is_string($count) ? (int) $count : -1;
    }
}
