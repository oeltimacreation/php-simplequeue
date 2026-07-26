<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Unit;

use Oeltima\SimpleQueue\Internal\WorkerLoopFailureHandler;
use Oeltima\SimpleQueue\Internal\WorkerPolicy;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class WorkerObservabilityTest extends TestCase
{
    public function testInfrastructureEventContainsNoThrowableOrStackData(): void
    {
        $events = [];
        $handler = new WorkerLoopFailureHandler(new NullLogger(), new WorkerPolicy(0, 0));

        $count = $handler->handle(
            new \PDOException('Connection lost'),
            0,
            static function (string $event, array $data) use (&$events): void {
                $events[$event] = $data;
            }
        );

        self::assertSame(1, $count);
        self::assertSame([
            'error' => 'Connection lost',
            'exception_class' => \PDOException::class,
        ], $events['infra_error']);
        self::assertArrayNotHasKey('exception', $events['infra_error']);
        self::assertArrayNotHasKey('trace', $events['infra_error']);
    }
}
