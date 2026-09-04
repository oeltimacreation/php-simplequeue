<?php

declare(strict_types=1);

use Oeltima\SimpleQueue\Contract\JobContextInterface;
use Oeltima\SimpleQueue\Contract\JobHandlerInterface;
use Oeltima\SimpleQueue\Contract\JobMiddlewareInterface;

final class NoopBenchmarkHandler implements JobHandlerInterface
{
    /**
     * @param array<string, mixed> $payload Benchmark payload
     * @return array{job_id: int} Minimal benchmark result
     */
    public function handle(int $jobId, array $payload, ?callable $progressCallback = null): array
    {
        return ['job_id' => $jobId];
    }
}

final class FailingBenchmarkHandler implements JobHandlerInterface
{
    /** @param array<string, mixed> $payload Benchmark payload */
    public function handle(int $jobId, array $payload, ?callable $progressCallback = null): never
    {
        throw new RuntimeException('benchmark retry');
    }
}

final class NoopBenchmarkMiddleware implements JobMiddlewareInterface
{
    public function process(JobContextInterface $context): mixed
    {
        return $context->proceed();
    }
}

/** @return class-string<JobHandlerInterface> */
function benchmarkHandler(BenchmarkScenario $scenario): string
{
    $retry = BenchmarkScenario::named(['value' => 'worker.retry']);
    return $scenario->sameAs($retry) ? FailingBenchmarkHandler::class : NoopBenchmarkHandler::class;
}
