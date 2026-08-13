<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Oeltima\SimpleQueue\Contract\JobContextInterface;
use Oeltima\SimpleQueue\Contract\JobHandlerInterface;
use Oeltima\SimpleQueue\Contract\JobMiddlewareInterface;
use Oeltima\SimpleQueue\Driver\InMemoryQueueDriver;
use Oeltima\SimpleQueue\JobDispatcher;
use Oeltima\SimpleQueue\JobRegistry;
use Oeltima\SimpleQueue\QueueManager;
use Oeltima\SimpleQueue\Storage\InMemoryJobStorage;
use Oeltima\SimpleQueue\Worker;

final class PrintMiddlewareMessage implements JobHandlerInterface
{
    public function handle(int $jobId, array $payload, ?callable $progress = null): mixed
    {
        $message = (string) ($payload['message'] ?? 'No message');
        echo "Handler processed job #{$jobId}: {$message}\n";

        return ['message' => $message];
    }
}

final class TimingMiddleware implements JobMiddlewareInterface
{
    public function process(JobContextInterface $context): mixed
    {
        $startedAt = microtime(true);
        echo sprintf(
            "Before %s job #%d (attempt %d)\n",
            $context->getType(),
            $context->getJobId(),
            $context->getAttempts()
        );

        $result = $context->proceed();

        echo sprintf(
            "After job #%d: %.2f ms\n",
            $context->getJobId(),
            (microtime(true) - $startedAt) * 1000
        );

        return $result;
    }
}

$storage = new InMemoryJobStorage();
$queues = new QueueManager(new InMemoryQueueDriver());
$registry = new JobRegistry();
$registry->register('message.print', PrintMiddlewareMessage::class);
$registry->middleware->register(new TimingMiddleware());

$dispatcher = new JobDispatcher($storage, $queues);
$jobId = $dispatcher->dispatch('message.print', ['message' => 'Hello through middleware!']);
$worker = new Worker($storage, $queues, $registry, options: ['lock_file' => null]);
$worker->processOne();

$job = $dispatcher->getStatus($jobId);
echo "Status: {$job?->status->value}\n";
