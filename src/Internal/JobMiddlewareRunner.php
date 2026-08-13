<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Internal;

use Oeltima\SimpleQueue\Contract\ClaimedJob;
use Oeltima\SimpleQueue\Contract\JobHandlerInterface;

/**
 * Builds and runs the worker's optional middleware pipeline.
 */
final class JobMiddlewareRunner
{
    /**
     * Execute a handler directly or through the registered middleware.
     *
     * @param list<\Oeltima\SimpleQueue\Contract\JobMiddlewareInterface> $middlewares Middleware in registration order
     * @param ClaimedJob $claim Current job claim
     * @param JobHandlerInterface $handler Resolved job handler
     * @param callable(int, ?string=): void $progressCallback Handler progress callback
     * @return mixed Handler or middleware result
     */
    public static function run(
        array $middlewares,
        ClaimedJob $claim,
        JobHandlerInterface $handler,
        callable $progressCallback
    ): mixed {
        if ($middlewares === []) {
            return $handler->handle($claim->job->id, $claim->job->payload, $progressCallback);
        }

        $next = static function () use ($claim, $handler, $progressCallback): mixed {
            return $handler->handle($claim->job->id, $claim->job->payload, $progressCallback);
        };

        foreach (array_reverse($middlewares) as $middleware) {
            $continuation = $next;
            $next = static function () use ($claim, $middleware, $continuation): mixed {
                $context = new JobExecutionContext(
                    $claim->job->id,
                    $claim->job->type,
                    $claim->job->payload,
                    $claim->job->queue,
                    $claim->job->attempts + 1,
                    $continuation
                );

                return $middleware->process($context);
            };
        }

        return $next();
    }
}
