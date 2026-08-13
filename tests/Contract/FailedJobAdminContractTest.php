<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Contract;

use Oeltima\SimpleQueue\AdminManager;
use Oeltima\SimpleQueue\Contract\JobStatus;
use Oeltima\SimpleQueue\Contract\JobStorageAdminInterface;
use Oeltima\SimpleQueue\Contract\JobStorageInterface;
use Oeltima\SimpleQueue\Contract\SupportsFailedJobAdministration;
use Oeltima\SimpleQueue\Driver\DatabaseQueueDriver;
use Oeltima\SimpleQueue\Driver\InMemoryQueueDriver;
use Oeltima\SimpleQueue\QueueManager;
use Oeltima\SimpleQueue\Storage\InMemoryJobStorage;
use Oeltima\SimpleQueue\Storage\PdoJobStorage;
use Oeltima\SimpleQueue\Tests\DbHelper;
use Oeltima\SimpleQueue\Tests\Support\FrozenClock;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FailedJobAdminContractTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function storageBackends(): iterable
    {
        yield 'in-memory' => ['memory'];
        yield 'SQLite PDO' => ['pdo'];
    }

    #[DataProvider('storageBackends')]
    public function testListsAndInspectsOnlyFailedJobs(string $backend): void
    {
        $storage = $this->storage($backend);
        $failedId = $this->failJob($storage, 'failed.one', 'alpha');
        $secondFailedId = $this->failJob($storage, 'failed.two', 'beta');
        $pendingId = $storage->createJob('pending.one', [], 'alpha');
        $admin = $this->admin($storage);

        self::assertSame([$failedId], array_column($admin->listFailed('alpha'), 'id'));
        self::assertSame([$secondFailedId], array_column($admin->listFailed(limit: 1), 'id'));
        self::assertSame([$failedId], array_column($admin->listFailed(limit: 1, offset: 1), 'id'));
        self::assertSame($failedId, $admin->inspectFailed($failedId)?->id);
        self::assertNull($admin->inspectFailed($pendingId));
    }

    #[DataProvider('storageBackends')]
    public function testRequeueResetsFailureStateAndNotifiesTheQueue(string $backend): void
    {
        $storage = $this->storage($backend);
        $jobId = $this->failJob($storage, 'failed.retry', 'emails');
        $driver = new InMemoryQueueDriver();
        $admin = new AdminManager($storage, new QueueManager($driver));

        self::assertTrue($admin->requeueFailed($jobId));
        $job = $storage->find($jobId);
        self::assertNotNull($job);
        self::assertSame(JobStatus::Pending, $job->status);
        self::assertSame(0, $job->attempts);
        self::assertNull($job->completedAt);
        self::assertNull($job->errorMessage);
        self::assertNull($job->errorTrace);
        self::assertSame([$jobId], $driver->getPending('emails'));
        self::assertFalse($admin->requeueFailed($jobId));
    }

    #[DataProvider('storageBackends')]
    public function testPurgeRemovesTheFailedRowAndAllNotifications(string $backend): void
    {
        $storage = $this->storage($backend);
        $jobId = $this->failJob($storage, 'failed.purge', 'emails');
        $driver = new InMemoryQueueDriver();
        $driver->enqueue('emails', $jobId);
        self::assertSame($jobId, $driver->dequeue('emails', 0));
        $driver->enqueueDelayed('emails', $jobId, time() + 60);
        $admin = new AdminManager($storage, new QueueManager($driver));

        self::assertTrue($admin->purgeFailed($jobId));
        self::assertNull($storage->find($jobId));
        self::assertSame([], $driver->getPending('emails'));
        self::assertSame([], $driver->getProcessing('emails'));
        self::assertSame([], $driver->getDelayed('emails'));
        self::assertFalse($admin->purgeFailed($jobId));
    }

    #[DataProvider('storageBackends')]
    public function testDatabasePollingBackendKeepsAdminTransitionsStorageGated(string $backend): void
    {
        $storage = $this->storage($backend);
        $jobId = $this->failJob($storage, 'failed.database', 'database');
        $driver = new DatabaseQueueDriver($storage);
        $admin = new AdminManager($storage, new QueueManager($driver));

        self::assertTrue($admin->requeueFailed($jobId));
        $claim = $storage->claimById($jobId, 'database-admin-worker');
        self::assertNotNull($claim);
        self::assertTrue($storage->markFailed($claim, 'failed again'));
        self::assertTrue($admin->purgeFailed($jobId));
        self::assertNull($storage->find($jobId));
    }

    /**
     * @param JobStorageInterface&JobStorageAdminInterface&SupportsFailedJobAdministration $storage
     */
    private function admin(
        JobStorageInterface&JobStorageAdminInterface&SupportsFailedJobAdministration $storage
    ): AdminManager {
        return new AdminManager($storage, new QueueManager(new InMemoryQueueDriver()));
    }

    /**
     * @param JobStorageInterface&JobStorageAdminInterface&SupportsFailedJobAdministration $storage
     */
    private function failJob(
        JobStorageInterface&JobStorageAdminInterface&SupportsFailedJobAdministration $storage,
        string $type,
        string $queue
    ): int {
        $jobId = $storage->createJob($type, [], $queue);
        $claim = $storage->claimById($jobId, 'admin-contract-worker');
        self::assertNotNull($claim);
        self::assertTrue($storage->markFailed($claim, 'permanent failure', 'bounded trace'));

        return $jobId;
    }

    /**
     * @return JobStorageInterface&JobStorageAdminInterface&SupportsFailedJobAdministration
     */
    private function storage(string $backend): JobStorageInterface&JobStorageAdminInterface&SupportsFailedJobAdministration
    {
        $clock = new FrozenClock();
        if ($backend === 'memory') {
            return new InMemoryJobStorage($clock);
        }

        $pdo = new PDO('sqlite::memory:');
        DbHelper::createSchema($pdo);

        return new PdoJobStorage($pdo, 'background_jobs', $clock);
    }
}
