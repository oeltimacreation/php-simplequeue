<?php

declare(strict_types=1);

use Oeltima\SimpleQueue\Contract\JobHandlerInterface;

final class NoopBenchmarkHandler implements JobHandlerInterface
{
    public function handle(int $jobId, array $payload, ?callable $progressCallback = null): array
    {
        return ['job_id' => $jobId];
    }
}

final class FailingBenchmarkHandler implements JobHandlerInterface
{
    public function handle(int $jobId, array $payload, ?callable $progressCallback = null): never
    {
        throw new RuntimeException('benchmark retry');
    }
}

/** @return class-string<JobHandlerInterface> */
function benchmarkHandler(BenchmarkScenario $scenario): string
{
    $retry = BenchmarkScenario::named(['value' => 'worker.retry']);
    return $scenario->sameAs($retry) ? FailingBenchmarkHandler::class : NoopBenchmarkHandler::class;
}
