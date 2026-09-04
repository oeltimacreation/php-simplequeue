<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Unit;

use Oeltima\SimpleQueue\Driver\RedisQueueDriver;
use Oeltima\SimpleQueue\Exception\QueueException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Predis\Client;
use Predis\ClientInterface;
use Predis\Command\CommandInterface;
use Predis\Command\FactoryInterface;
use Predis\Configuration\OptionsInterface;
use Predis\Connection\ConnectionInterface;
use Predis\Response\ServerException;

/**
 * Mock Redis client for testing.
 *
 * Predis uses magic __call for Redis commands, so we need a concrete mock.
 */
class MockRedisClient implements ClientInterface
{
    /** @var list<array{method: string, args: list<mixed>}> */
    public array $calls = [];

    /** @var array<string, mixed> */
    public array $returns = [];

    /** @var array<string, \Throwable> */
    public array $throws = [];

    public ?MockRedisPipeline $pipeline = null;

    /** @var list<MockRedisPipeline> */
    public array $pipelines = [];

    /** @var list<list<mixed>> */
    public array $pipelineReturns = [];

    public ConnectionInterface $connection;
    private Client $delegate;

    public function __construct()
    {
        $this->delegate = new Client();
        $this->connection = $this->delegate->getConnection();
    }

    public function getCommandFactory(): FactoryInterface
    {
        return $this->delegate->getCommandFactory();
    }

    public function getOptions(): OptionsInterface
    {
        return $this->delegate->getOptions();
    }

    public function connect(): void
    {
    }

    public function disconnect(): void
    {
    }

    public function getConnection(): ConnectionInterface
    {
        return $this->connection;
    }

    /**
     * @param string $commandID Command identifier
     * @param array<array-key, mixed> $arguments Command arguments
     */
    public function createCommand(mixed $commandID, mixed $arguments = []): CommandInterface
    {
        return $this->delegate->createCommand($commandID, $arguments);
    }

    public function executeCommand(CommandInterface $command): mixed
    {
        return null;
    }

    /**
     * @param string $commandID Command identifier
     * @param array<array-key, mixed> $arguments Command arguments
     */
    public function __call(mixed $commandID, mixed $arguments): mixed
    {
        $arguments = array_values($arguments);
        $this->calls[] = ['method' => $commandID, 'args' => $arguments];
        if (isset($this->throws[$commandID])) {
            throw $this->throws[$commandID];
        }
        return $this->returns[$commandID] ?? null;
    }

    public function pipeline(): MockRedisPipeline
    {
        $this->pipelines[] = $this->pipeline = MockRedisPipeline::fromReturns(array_shift($this->pipelineReturns));
        return $this->pipeline;
    }
}

class MockRedisPipeline
{
    /** @var list<array{method: string, args: list<mixed>}> */
    public array $calls = [];
    public bool $executed = false;

    /** @param list<mixed> $returns */
    public function __construct(private readonly array $returns = [])
    {
    }

    /** @param list<mixed>|null $returns */
    public static function fromReturns(?array $returns): self
    {
        return new self($returns ?? []);
    }

    public function __call(mixed $method, mixed $arguments): self
    {
        if (!is_string($method) || !is_array($arguments)) {
            throw new \LogicException('Invalid mock pipeline invocation');
        }
        $arguments = array_values($arguments);
        $this->calls[] = ['method' => $method, 'args' => $arguments];
        return $this;
    }

    /** @return list<mixed> */
    public function execute(): array
    {
        $this->executed = true;
        return $this->returns;
    }
}

class RedisQueueDriverTest extends TestCase
{
    private RedisQueueDriver $driver;
    private MockRedisClient $redis;

    protected function setUp(): void
    {
        $this->redis = new MockRedisClient();
        $this->driver = new RedisQueueDriver($this->redis, 'test');
    }

    /** @return list<string> */
    private function callMethods(): array
    {
        return array_column($this->redis->calls, 'method');
    }

    /**
     * @return list<array{method: string, args: array<int, mixed>}>
     */
    private function callsFor(string $method): array
    {
        return array_values(array_filter(
            $this->redis->calls,
            static fn (array $call): bool => $call['method'] === $method
        ));
    }

    private function expectColdScriptCache(): void
    {
        $this->redis->throws['evalsha'] = new ServerException('NOSCRIPT No matching script. Please use EVAL.');
    }

    #[DataProvider('nonBlockingDequeueTransport')]
    public function testNonBlockingDequeueUsesAtomicLuaScript(bool $coldCache): void
    {
        if ($coldCache) {
            $this->expectColdScriptCache();
            $this->redis->returns['eval'] = '123';
        } else {
            $this->redis->returns['evalsha'] = '123';
        }

        $jobId = $this->driver->dequeue('default', 0);

        self::assertEquals(123, $jobId);

        $methods = $this->callMethods();
        self::assertContains('evalsha', $methods, 'Should attempt cached atomic Lua dequeue');
        if ($coldCache) {
            self::assertContains('eval', $methods, 'NOSCRIPT should fall back to EVAL');
        } else {
            self::assertNotContains('eval', $methods);
        }
        self::assertNotContains('lmove', $methods, 'Lua owns the non-blocking move');
        self::assertNotContains('blmove', $methods, 'Should not use blocking blmove');
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function nonBlockingDequeueTransport(): array
    {
        return [
            'warm script cache' => [false],
            'cold script cache (NOSCRIPT fallback)' => [true],
        ];
    }

    public function testScriptRunnerRethrowsNonNoScriptErrors(): void
    {
        $this->redis->throws['evalsha'] = new ServerException('WRONGTYPE Operation against a key holding the wrong kind of value');

        $this->expectException(ServerException::class);
        $this->expectExceptionMessage('WRONGTYPE');

        $this->driver->dequeue('default', 0);

        $methods = $this->callMethods();
        self::assertContains('evalsha', $methods);
        self::assertNotContains('eval', $methods, 'Non-NOSCRIPT errors must not trigger the EVAL fallback');
    }

    public function testDequeueBlockingWhenTimeoutPositive(): void
    {
        $this->redis->returns['blmove'] = '456';

        $jobId = $this->driver->dequeue('default', 5);

        self::assertEquals(456, $jobId);

        $methods = $this->callMethods();
        self::assertContains('blmove', $methods, 'Should use blocking blmove');
        self::assertNotContains('lmove', $methods, 'Should not use non-blocking lmove');
    }

    public function testDequeueReturnsNullWhenEmpty(): void
    {
        $this->redis->returns['evalsha'] = null;

        $jobId = $this->driver->dequeue('default', 0);

        self::assertNull($jobId);
    }

    public function testDequeueRejectsMalformedRedisJobIdWithoutCastingIt(): void
    {
        $this->redis->returns['evalsha'] = '0';

        $this->expectException(QueueException::class);
        try {
            $this->driver->dequeue('default', 0);
        } finally {
            $methods = $this->callMethods();
            self::assertContains('lrem', $methods);
            self::assertContains('zrem', $methods);
        }
    }

    public function testBlockingDequeueDiscardsMalformedNonScalarResponse(): void
    {
        $this->redis->returns['blmove'] = ['unexpected'];

        $this->expectException(QueueException::class);
        try {
            $this->driver->dequeue('default', 5);
        } finally {
            $cleanupCalls = array_values(array_filter(
                $this->redis->calls,
                static fn(array $call): bool => in_array($call['method'], ['lrem', 'zrem'], true)
            ));
            self::assertCount(2, $cleanupCalls);
            self::assertSame('', $cleanupCalls[0]['args'][2]);
            self::assertSame('', $cleanupCalls[1]['args'][1]);
        }
    }

    public function testStaleRecoveryRepairsUnscoredProcessingInBoundedSlice(): void
    {
        $this->redis->returns['lrange'] = ['123'];
        $this->redis->pipelineReturns = [[null]];
        $this->redis->returns['evalsha'] = 0;

        self::assertSame(0, $this->driver->recoverStaleProcessing('default', 60, 1));

        $methods = $this->callMethods();
        self::assertContains('lrange', $methods);
        self::assertSame(['zscore'], array_column($this->redis->pipelines[0]->calls, 'method'));
        self::assertSame(['zadd'], array_column($this->redis->pipelines[1]->calls, 'method'));
    }

    public function testStaleRecoveryPipelinesScoreChecksWithoutUnneededWrites(): void
    {
        $this->redis->returns['lrange'] = ['123', '456'];
        $this->redis->pipelineReturns = [['1700000000', '1700000001']];
        $this->redis->returns['evalsha'] = 0;

        self::assertSame(0, $this->driver->recoverStaleProcessing('default', 60, 2));

        self::assertCount(1, $this->redis->pipelines);
        self::assertSame(['zscore', 'zscore'], array_column($this->redis->pipelines[0]->calls, 'method'));
    }

    public function testAckAtomicallyPreservesDuplicateProcessingVisibility(): void
    {
        $this->driver->ack('default', 123);

        $calls = $this->callsFor('eval');
        self::assertCount(1, $calls);
        self::assertSame(2, $calls[0]['args'][1]);
        self::assertSame('test:queue:default:processing', $calls[0]['args'][2]);
        self::assertSame('test:queue:default:processing_z', $calls[0]['args'][3]);
        self::assertSame('123', $calls[0]['args'][4]);
        self::assertIsString($calls[0]['args'][0]);
        self::assertStringContainsString("redis.call('LPOS'", $calls[0]['args'][0]);
    }

    public function testRemoveCleansEveryNotificationStructure(): void
    {
        $this->driver->remove('default', 123);

        $calls = $this->callsFor('eval');
        self::assertCount(1, $calls);
        self::assertSame(4, $calls[0]['args'][1]);
        self::assertSame('test:queue:default:pending', $calls[0]['args'][2]);
        self::assertSame('test:queue:default:delayed', $calls[0]['args'][3]);
        self::assertSame('test:queue:default:processing', $calls[0]['args'][4]);
        self::assertSame('test:queue:default:processing_z', $calls[0]['args'][5]);
        self::assertSame('123', $calls[0]['args'][6]);
    }

    public function testHeartbeatRefreshesProcessingVisibility(): void
    {
        $this->driver->heartbeatProcessing('default', 123);

        $calls = $this->callsFor('zadd');
        self::assertCount(1, $calls);
        self::assertSame('test:queue:default:processing_z', $calls[0]['args'][0]);
        $members = $calls[0]['args'][1];
        self::assertIsArray($members);
        self::assertArrayHasKey(123, $members);
    }

    public function testBoundedMembershipChecksPendingAndDelayedStructures(): void
    {
        $this->redis->returns['lpos'] = 0;
        $this->redis->returns['zscore'] = '1700000000';

        self::assertTrue($this->driver->hasPendingJob('default', 123, 25));
        self::assertTrue($this->driver->hasDelayedJob('default', 123));
        self::assertSame(
            ['test:queue:default:pending', '123', 'MAXLEN', 25],
            $this->callsFor('lpos')[0]['args']
        );
        self::assertSame(['test:queue:default:delayed', '123'], $this->callsFor('zscore')[0]['args']);
    }

    public function testNackWithDelayAddsToDelayedZset(): void
    {
        $this->driver->nack('default', 123, 60);

        $calls = $this->callsFor('eval');
        self::assertCount(1, $calls);
        self::assertSame(4, $calls[0]['args'][1]);
        self::assertSame('test:queue:default:delayed', $calls[0]['args'][4]);
        self::assertSame('123', $calls[0]['args'][6]);
        self::assertSame('60', $calls[0]['args'][7]);
        self::assertIsString($calls[0]['args'][0]);
        self::assertStringContainsString("redis.call('ZADD', KEYS[3]", $calls[0]['args'][0]);
    }

    public function testNackWithoutDelayReenqueuesImmediately(): void
    {
        $this->driver->nack('default', 123, 0);

        $calls = $this->callsFor('eval');
        self::assertCount(1, $calls);
        self::assertSame('test:queue:default:pending', $calls[0]['args'][5]);
        self::assertSame('0', $calls[0]['args'][7]);
        self::assertIsString($calls[0]['args'][0]);
        self::assertStringContainsString("redis.call('LPUSH', KEYS[4]", $calls[0]['args'][0]);
    }

    #[DataProvider('delayedPromotionTransport')]
    public function testPromoteDelayedJobsUsesAtomicLuaScript(bool $coldCache): void
    {
        if ($coldCache) {
            $this->expectColdScriptCache();
            $this->redis->returns['eval'] = 3;
        } else {
            $this->redis->returns['evalsha'] = 3;
        }

        self::assertSame(3, $this->driver->promoteDelayedJobs('default', 50));

        $this->assertScriptInvocation(
            $coldCache ? 'eval' : 'evalsha',
            !$coldCache,
            ['test:queue:default:delayed', 'test:queue:default:pending'],
            '50'
        );
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function delayedPromotionTransport(): array
    {
        return [
            'warm script cache' => [false],
            'cold script cache (NOSCRIPT fallback)' => [true],
        ];
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function malformedScriptCounts(): iterable
    {
        yield 'negative integer' => [-1];
        yield 'negative string' => ['-1'];
        yield 'leading zero' => ['01'];
        yield 'fractional number' => [1.5];
        yield 'fractional string' => ['1.5'];
        yield 'overflow' => [str_repeat('9', strlen((string) PHP_INT_MAX) + 1)];
        yield 'null' => [null];
    }

    #[DataProvider('malformedScriptCounts')]
    public function testPromotionRejectsMalformedScriptCount(mixed $response): void
    {
        $this->redis->returns['evalsha'] = $response;

        $this->expectException(QueueException::class);
        $this->expectExceptionMessage('Redis returned a malformed integer response');
        $this->driver->promoteDelayedJobs('default', 50);
    }

    public function testStaleRecoveryRejectsMalformedScriptCount(): void
    {
        $this->redis->returns['lrange'] = [];
        $this->redis->returns['evalsha'] = '-1';

        $this->expectException(QueueException::class);
        $this->driver->recoverStaleProcessing('default', 60, 50);
    }

    public function testEnqueueDelayedBatchSendsSingleZadd(): void
    {
        $this->driver->enqueueDelayedBatch('default', [1, 2, 3], 1_700_000_100);

        $zaddCalls = $this->callsFor('zadd');
        self::assertCount(1, $zaddCalls);

        $call = $zaddCalls[0];
        self::assertEquals('test:queue:default:delayed', $call['args'][0]);
        self::assertEquals([1 => 1_700_000_100, 2 => 1_700_000_100, 3 => 1_700_000_100], $call['args'][1]);
    }

    public function testEnqueueDelayedAddsOneAbsoluteTimestamp(): void
    {
        $this->driver->enqueueDelayed('default', 7, 1_700_000_100);

        $calls = $this->callsFor('zadd');
        self::assertCount(1, $calls);
        self::assertSame('test:queue:default:delayed', $calls[0]['args'][0]);
        self::assertSame([7 => 1_700_000_100], $calls[0]['args'][1]);
    }

    public function testEnqueueDelayedBatchEmptyDoesNothing(): void
    {
        $this->driver->enqueueDelayedBatch('default', [], 1_700_000_100);

        self::assertCount(0, $this->callsFor('zadd'));
    }

    public function testRecoverStaleProcessingUsesCachedLuaScript(): void
    {
        $this->redis->returns['evalsha'] = 2;
        $this->redis->returns['lrange'] = [];

        self::assertSame(2, $this->driver->recoverStaleProcessing('default', 600, 75));

        $this->assertScriptInvocation(
            'evalsha',
            true,
            ['test:queue:default:processing_z', 'test:queue:default:processing', 'test:queue:default:pending'],
            '75'
        );
    }

    /**
     * Assert a single Lua script invocation's key and argument shape.
     *
     * @param string $transportMethod Command used to run the script (evalsha or eval)
     * @param bool $expectSha True when the first argument must be a 40-character SHA1 digest
     * @param list<string> $keys Expected script keys in order
     * @param string $lastArgument Expected final non-key script argument
     */
    private function assertScriptInvocation(
        string $transportMethod,
        bool $expectSha,
        array $keys,
        string $lastArgument
    ): void {
        $transportCalls = $this->callsFor($transportMethod);
        self::assertCount(1, $transportCalls);
        $call = $transportCalls[0];
        $script = $call['args'][0];
        self::assertIsString($script);
        if ($expectSha) {
            self::assertSame(40, strlen($script)); // SHA1 digest
        } else {
            self::assertStringContainsString('ZRANGEBYSCORE', $script);
        }
        $numKeys = count($keys);
        self::assertSame($numKeys, $call['args'][1]);
        foreach ($keys as $index => $key) {
            self::assertSame($key, $call['args'][$index + 2]);
        }
        self::assertSame($lastArgument, $call['args'][$numKeys + 3]);
    }

    public function testClearRemovesAllKeys(): void
    {
        $this->driver->clear('default');

        $delCalls = $this->callsFor('del');
        self::assertCount(1, $delCalls);

        $keys = $delCalls[0]['args'][0];
        self::assertIsArray($keys);
        self::assertCount(4, $keys);
        self::assertContains('test:queue:default:pending', $keys);
        self::assertContains('test:queue:default:processing', $keys);
        self::assertContains('test:queue:default:processing_z', $keys);
        self::assertContains('test:queue:default:delayed', $keys);
    }

    public function testGetDelayedCount(): void
    {
        $this->redis->returns['zcard'] = 5;

        $count = $this->driver->getDelayedCount('default');

        self::assertEquals(5, $count);
    }

    public function testEnqueueBatchUsesSingleLpush(): void
    {
        $this->driver->enqueueBatch('default', [1, 2, 3]);

        $lpushCalls = $this->callsFor('lpush');
        self::assertCount(1, $lpushCalls);

        $call = $lpushCalls[0];
        self::assertEquals('test:queue:default:pending', $call['args'][0]);
        self::assertEquals(['1', '2', '3'], $call['args'][1]);
    }

    public function testEnqueueBatchEmptyArrayDoesNothing(): void
    {
        $this->driver->enqueueBatch('default', []);

        self::assertCount(0, $this->callsFor('lpush'));
    }

    public function testGetPendingCount(): void
    {
        $this->redis->returns['llen'] = 10;

        $count = $this->driver->getPendingCount('default');

        self::assertEquals(10, $count);
    }

    public function testGetProcessingCount(): void
    {
        $this->redis->returns['llen'] = 3;

        $count = $this->driver->getProcessingCount('default');

        self::assertEquals(3, $count);
    }

    public function testQueueIdSnapshotsNormalizeRedisStrings(): void
    {
        $this->redis->returns['lrange'] = ['10', '20'];
        $this->redis->returns['zrange'] = ['30', '40'];

        self::assertSame([10, 20], $this->driver->getPendingIds('default'));
        self::assertSame([30, 40], $this->driver->getDelayedIds('default'));
    }

    public function testBatchReconciliationReturnsUniquePresentIdsInInputOrder(): void
    {
        $this->redis->returns['eval'] = ['3', '1', '3'];

        $present = $this->driver->reconcileNotifications(
            'default',
            [1 => 1_700_000_000, 2 => 1_700_000_100, 3 => 1_700_000_200],
            1_700_000_050,
            250
        );

        self::assertSame([1, 3], $present);
        $calls = $this->callsFor('eval');
        self::assertCount(1, $calls);
        self::assertSame(2, $calls[0]['args'][1]);
        self::assertSame('test:queue:default:pending', $calls[0]['args'][2]);
        self::assertSame('test:queue:default:delayed', $calls[0]['args'][3]);
        self::assertSame('1700000050', $calls[0]['args'][4]);
        self::assertSame('250', $calls[0]['args'][5]);
        self::assertSame('3', $calls[0]['args'][6]);
    }

    public function testReconciliationRejectsMalformedPositiveIdResponse(): void
    {
        $this->redis->returns['eval'] = ['0'];

        $this->expectException(QueueException::class);
        $this->driver->reconcileNotifications('default', [1 => 1_700_000_000], 1_700_000_001, 250);
    }

    public function testMembershipAndReconciliationValidateBeforeRedisCommand(): void
    {
        foreach (
            [
                fn () => $this->driver->hasPendingJob('default', 0, 1),
                fn () => $this->driver->hasDelayedJob('default', 0),
                fn () => $this->driver->reconcileNotifications('default', [], 0, 1),
            ] as $operation
        ) {
            try {
                $operation();
                self::fail('Invalid Redis boundary must fail');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        self::assertSame([], $this->redis->calls);
    }

    public function testIsAvailableReturnsTrueOnSuccessfulPing(): void
    {
        $this->redis->returns['ping'] = 'PONG';

        self::assertTrue($this->driver->isAvailable());
    }

    public function testEnqueueAddsToCorrectKey(): void
    {
        $this->driver->enqueue('myqueue', 42);

        $lpushCalls = $this->callsFor('lpush');

        self::assertEquals('test:queue:myqueue:pending', $lpushCalls[0]['args'][0]);
    }

    public function testValidateTimeoutThrowsExceptionWhenUnsafe(): void
    {
        $this->redis->connection = (new Client(['read_write_timeout' => 5]))->getConnection();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsafe timeout configuration');

        $this->driver->validateTimeout(5);
    }

    public function testValidateTimeoutAllowsSafeTimeouts(): void
    {
        $this->redis->connection = (new Client(['read_write_timeout' => 60]))->getConnection();

        $this->expectNotToPerformAssertions();
        $this->driver->validateTimeout(5);
    }

    public function testRealPredisTimeoutValidationThrowsOnUnsafe(): void
    {
        $redisClient = new \Predis\Client([
            'read_write_timeout' => 5,
        ]);
        $driver = new RedisQueueDriver($redisClient, 'test');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsafe timeout configuration');

        $driver->validateTimeout(5);
    }

    public function testRealPredisTimeoutValidationPassesOnSafe(): void
    {
        $redisClient = new \Predis\Client([
            'read_write_timeout' => 60,
        ]);
        $driver = new RedisQueueDriver($redisClient, 'test');

        $this->expectNotToPerformAssertions();
        $driver->validateTimeout(5);
    }
}
