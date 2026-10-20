<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Internal;

use Oeltima\SimpleQueue\Contract\DelayedBatch;
use Oeltima\SimpleQueue\Exception\JobNotificationException;
use Oeltima\SimpleQueue\QueueManager;

/**
 * @internal Groups notifications after atomic creation without replaying storage.
 * @phpstan-import-type JobDefinitionShape from \Oeltima\SimpleQueue\Contract\JobStorageInterface
 * @phpstan-type Notification array{jobId: int, queue: string, availableAt: int|null}
 * @phpstan-type NotificationGroup array{queue: string, at: int|null, ids: list<int>}
 */
final readonly class IdempotentBatchNotifier
{
    /**
     * @param QueueManager $manager Queue transport
     * @param \Closure(string, list<int>): void $notifyBatch Immediate batch notification
     * @param \Closure(int|\DateTimeInterface|null): (int|null) $resolve Availability resolver
     */
    public function __construct(
        private QueueManager $manager,
        private \Closure $notifyBatch,
        private \Closure $resolve
    ) {
    }

    /**
     * @param list<JobDefinitionShape> $jobs Stored definitions
     * @param list<\Oeltima\SimpleQueue\Contract\IdempotentJobResult> $results Ordered creation outcomes
     * @return void Notify only newly created jobs
     */
    public function notify(array $jobs, array $results): void
    {
        $notifications = $this->createdNotifications($jobs, $results);
        foreach ($this->groups($notifications) as $group) {
            $this->notifyGroup($group, $notifications);
        }
    }

    /**
     * @param list<JobDefinitionShape> $jobs Stored definitions
     * @param list<\Oeltima\SimpleQueue\Contract\IdempotentJobResult> $results Creation outcomes
     * @return list<Notification> Created notification identities and schedules
     */
    private function createdNotifications(array $jobs, array $results): array
    {
        $notifications = [];
        foreach ($results as $index => $result) {
            if (!$result->created) {
                continue;
            }
            $notifications[] = [
                'jobId' => $result->jobId,
                'queue' => $jobs[$index]['queue'] ?? 'default',
                'availableAt' => ($this->resolve)($jobs[$index]['availableAt'] ?? null),
            ];
        }
        return $notifications;
    }

    /**
     * @param list<Notification> $notifications Created notifications
     * @return array<string, NotificationGroup> Groups in first-notification order
     */
    private function groups(array $notifications): array
    {
        $groups = [];
        foreach ($notifications as $notification) {
            $queue = $notification['queue'];
            $at = $notification['availableAt'];
            $key = serialize([$queue, $at]);
            $groups[$key] ??= ['queue' => $queue, 'at' => $at, 'ids' => []];
            $groups[$key]['ids'][] = $notification['jobId'];
        }
        return $groups;
    }

    /**
     * @param NotificationGroup $group Notification group
     * @param list<Notification> $notifications All created notifications
     */
    private function notifyGroup(array $group, array $notifications): void
    {
        try {
            if ($group['at'] === null) {
                ($this->notifyBatch)($group['queue'], $group['ids']);
            } else {
                $this->manager->enqueueDelayedBatch(new DelayedBatch($group['ids'], $group['queue'], $group['at']));
            }
        } catch (\Throwable $exception) {
            throw new JobNotificationException(
                array_column($notifications, 'jobId'),
                $group['queue'],
                $group['at'],
                $exception,
                $notifications
            );
        }
    }
}
