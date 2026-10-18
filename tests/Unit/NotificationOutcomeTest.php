<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Unit;

use Oeltima\SimpleQueue\Contract\QueueDriverInterface;
use Oeltima\SimpleQueue\Contract\JobStatus;
use Oeltima\SimpleQueue\Exception\JobNotificationException;
use Oeltima\SimpleQueue\JobDispatcher;
use Oeltima\SimpleQueue\QueueManager;
use Oeltima\SimpleQueue\Storage\InMemoryJobStorage;
use PHPUnit\Framework\TestCase;

final class NotificationOutcomeTest extends TestCase
{
    public function testIdempotentRetryReturnsAcknowledgedJobWithoutBlindNotificationReplay(): void
    {
        $storage = new InMemoryJobStorage();
        $driver = $this->createMock(QueueDriverInterface::class);
        $cause = new \RuntimeException('Transport unavailable');
        $driver->expects(self::once())->method('enqueue')->willThrowException($cause);
        $dispatcher = new JobDispatcher($storage, new QueueManager($driver));
        try {
            $dispatcher->dispatchIdempotent('test', [], 'request');
            self::fail('Notification failure must expose the acknowledged job');
        } catch (JobNotificationException $exception) {
            self::assertSame([1], $exception->jobIds);
            self::assertSame('default', $exception->queue);
            self::assertNull($exception->availableAt);
            self::assertSame($cause, $exception->getPrevious());
            self::assertSame(JobStatus::Pending, $storage->find(1)?->status);
        }
        self::assertSame(['job_id' => 1, 'created' => false], $dispatcher->dispatchIdempotent('test', [], 'request'));
        self::assertSame(1, $storage->count());
    }

    public function testPartialFallbackBatchNotificationReportsAllCreatedIds(): void
    {
        $storage = new InMemoryJobStorage();
        $driver = $this->createMock(QueueDriverInterface::class);
        $driver->method('enqueue')->willReturnCallback(static function (string $queue, int $id): void {
            if ($id === 2) {
                throw new \RuntimeException('Uncertain second notification');
            }
        });
        try {
            (new JobDispatcher($storage, new QueueManager($driver)))->dispatchBatch('test', [[], [], []]);
            self::fail('Expected batch notification failure');
        } catch (JobNotificationException $exception) {
            self::assertSame([1, 2, 3], $exception->jobIds);
            self::assertSame(3, $storage->count());
        }
    }

    public function testMixedIdempotentBatchFailureCarriesEveryStoredNotification(): void
    {
        $storage = new InMemoryJobStorage();
        $driver = $this->createMock(QueueDriverInterface::class);
        $driver->method('enqueue')->willThrowException(new \RuntimeException('Unavailable'));
        try {
            (new JobDispatcher($storage, new QueueManager($driver)))->dispatchIdempotentBatch([
                ['type' => 'test', 'payload' => [], 'queue' => 'first', 'requestId' => 'one'],
                ['type' => 'test', 'payload' => [], 'queue' => 'second', 'requestId' => 'two'],
            ]);
            self::fail('Expected notification uncertainty');
        } catch (JobNotificationException $exception) {
            self::assertSame([1, 2], $exception->jobIds);
            self::assertSame(['first', 'second'], array_column($exception->notifications, 'queue'));
            self::assertSame('first', $exception->queue);
            self::assertSame(2, $storage->count());
        }
    }
}
