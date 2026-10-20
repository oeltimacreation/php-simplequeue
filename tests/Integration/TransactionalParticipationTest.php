<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Integration;

use Oeltima\SimpleQueue\Contract\JobStatus;
use Oeltima\SimpleQueue\Exception\SerializationException;
use Oeltima\SimpleQueue\Storage\PdoJobStorage;
use Oeltima\SimpleQueue\Tests\DbHelper;
use Oeltima\SimpleQueue\Tests\Support\FrozenClock;
use Oeltima\SimpleQueue\Tests\Support\StorageTransitionMatrix;
use Oeltima\SimpleQueue\Tests\Support\TransactionalOutbox;
use PDO;
use PHPUnit\Framework\TestCase;

final class TransactionalParticipationTest extends TestCase
{
    /** @return array{PDO, PdoJobStorage, TransactionalOutbox, FrozenClock} */
    private function fixture(): array
    {
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        DbHelper::createSchema($pdo);
        $pdo->exec('CREATE TABLE job_outbox (job_id INTEGER, operation TEXT, status TEXT, attempts INTEGER)');
        $pdo->exec('CREATE TABLE input_registrations (type TEXT)');
        $participant = new TransactionalOutbox();
        $clock = new FrozenClock();
        return [$pdo, new PdoJobStorage($pdo, clock: $clock, participant: $participant), $participant, $clock];
    }

    public function testSharedTransitionMatrixWithTransactionalApplicationWrites(): void
    {
        [, $storage] = $this->fixture();
        StorageTransitionMatrix::assertCreate($storage);
        StorageTransitionMatrix::assertClaim($storage);
        StorageTransitionMatrix::assertProgressAndCompletion($storage);
        StorageTransitionMatrix::assertRetry($storage);
        StorageTransitionMatrix::assertTerminalFailure($storage);
        StorageTransitionMatrix::assertGracefulRelease($storage);
        StorageTransitionMatrix::assertAdministration($storage);
        StorageTransitionMatrix::assertOwnership($storage);
    }

    public function testStaleTerminalizationWritesOutboxForScopedAndUnscopedRecovery(): void
    {
        [$pdo, $storage, , $clock] = $this->fixture();
        $first = $storage->createJob('test', [], maxAttempts: 1);
        $second = $storage->createJob('test', [], 'other', 1);
        $storage->claimById($first, 'old');
        $storage->claimById($second, 'old');
        $clock->advance(601);
        self::assertSame(1, $storage->recoverStaleJobsForQueue('default', 600, 100));
        self::assertSame(1, $storage->recoverStaleJobs(600));
        $statement = $pdo->query("SELECT attempts FROM job_outbox WHERE status = 'failed' ORDER BY job_id");
        self::assertNotFalse($statement);
        self::assertSame([1, 1], $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testParticipantFailureRollsBackStateAndOutboxAndNeverStealsCallerTransaction(): void
    {
        [$pdo, $storage, $participant] = $this->fixture();
        $id = $storage->createJob('test', []);
        $claim = $storage->claimById($id, 'worker');
        self::assertNotNull($claim);
        $pdo->beginTransaction();
        $participant->fail = true;
        try {
            $storage->markCompleted($claim, ['ok' => true]);
            self::fail('Outbox failure must roll back completion');
        } catch (\RuntimeException $exception) {
            self::assertSame('Outbox rejected transition', $exception->getMessage());
        }
        self::assertTrue($pdo->inTransaction());
        self::assertSame(JobStatus::Running, $storage->find($id)?->status);
        $participant->fail = false;
        self::assertTrue($storage->markCompleted($claim));
        $pdo->rollBack();
        self::assertSame(JobStatus::Running, $storage->find($id)->status);
        $statement = $pdo->query('SELECT COUNT(*) FROM job_outbox');
        self::assertNotFalse($statement);
        self::assertSame(1, $statement->fetchColumn());
    }

    public function testBatchRegistrationRollsBackAndSerializationRetainsCanonicalException(): void
    {
        [$pdo, $storage, $participant] = $this->fixture();
        $participant->fail = true;
        try {
            $storage->createJobs([['type' => 'first', 'payload' => []], ['type' => 'second', 'payload' => []]]);
            self::fail('Expected transactional creation failure');
        } catch (\RuntimeException) {
            self::assertSame(0, $storage->count());
        }
        $statement = $pdo->query('SELECT COUNT(*) FROM input_registrations');
        self::assertNotFalse($statement);
        self::assertSame(0, $statement->fetchColumn());
        $participant->fail = false;
        $id = $storage->createJob('test', []);
        $claim = $storage->claimById($id, 'worker');
        self::assertNotNull($claim);
        $this->expectException(SerializationException::class);
        $storage->markCompleted($claim, INF);
    }
}
