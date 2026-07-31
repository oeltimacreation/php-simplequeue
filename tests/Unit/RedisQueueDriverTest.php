<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Unit;

use Oeltima\SimpleQueue\Driver\RedisQueueDriver;
use Oeltima\SimpleQueue\Exception\QueueException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Predis\ClientInterface;
use Predis\Command\CommandInterface;
use Predis\Response\ServerException;

/**
 * Mock Redis client for testing.
 *
 * Predis uses magic __call for Redis commands, so we need a concrete mock.
 */
class MockRedisClient implements ClientInterface
{
    /** @var array<string, mixed> */
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

    public function getCommandFactory()
    {
        return null;
    }

    public function getOptions()
    {
        return null;
    }

    public function connect(): void
    {
    }

    public function disconnect(): void
    {
    }

    public $connection = null;

    public function getConnection()
    {
        return $this->connection;
    }

    public function createCommand($commandID, $arguments = [])
    {
        return null;
    }

    public function executeCommand(CommandInterface $command)
    {
        return null;
    }

    public function __call($commandID, $arguments)
    {
        $this->calls[] = ['method' => $commandID, 'args' => $arguments];
        if (isset($this->throws[$commandID])) {
            throw $this->throws[$commandID];
        }
        return $this->returns[$commandID] ?? null;
    }

    public function pipeline()
    {
        $this->pipelines[] = $this->pipeline = MockRedisPipeline::fromReturns(array_shift($this->pipelineReturns));
        return $this->pipeline;
    }
}

class MockRedisPipeline
{
    /** @var array<string, mixed> */
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

    public function __call($method, $arguments)
    {
        $this->calls[] = ['method' => $method, 'args' => $arguments];
        return $this;
    }

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

        $this->assertEquals(123, $jobId);

        $methods = $this->callMethods();
        $this->assertContains('evalsha', $methods, 'Should attempt cached atomic Lua dequeue');
        if ($coldCache) {
            $this->assertContains('eval', $methods, 'NOSCRIPT should fall back to EVAL');
        } else {
            $this->assertNotContains('eval', $methods);
        }
        $this->assertNotContains('lmove', $methods, 'Lua owns the non-blocking move');
        $this->assertNotContains('blmove', $methods, 'Should not use blocking blmove');
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
        $this->assertContains('evalsha', $methods);
        $this->assertNotContains('eval', $methods, 'Non-NOSCRIPT errors must not trigger the EVAL fallback');
    }

    public function testDequeueBlockingWhenTimeoutPositive(): void
    {
        $this->redis->returns['blmove'] = '456';

        $jobId = $this->driver->dequeue('default', 5);

        $this->assertEquals(456, $jobId);

        $methods = $this->callMethods();
        $this->assertContains('blmove', $methods, 'Should use blocking blmove');
        $this->assertNotContains('lmove', $methods, 'Should not use non-blocking lmove');
    }

    public function testDequeueReturnsNullWhenEmpty(): void
    {
        $this->redis->returns['evalsha'] = null;

        $jobId = $this->driver->dequeue('default', 0);

        $this->assertNull($jobId);
    }

    public function testDequeueRejectsMalformedRedisJobIdWithoutCastingIt(): void
    {
        $this->redis->returns['evalsha'] = '0';

        $this->expectException(QueueException::class);
        try {
            $this->driver->dequeue('default', 0);
        } finally {
            $methods = $this->callMethods();
            $this->assertContains('lrem', $methods);
            $this->assertContains('zrem', $methods);
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
            $this->assertCount(2, $cleanupCalls);
            $this->assertSame('', $cleanupCalls[0]['args'][2]);
            $this->assertSame('', $cleanupCalls[1]['args'][1]);
        }
    }

    public function testStaleRecoveryRepairsUnscoredProcessingInBoundedSlice(): void
    {
        $this->redis->returns['lrange'] = ['123'];
        $this->redis->pipelineReturns = [[null]];
        $this->redis->returns['evalsha'] = 0;

        $this->assertSame(0, $this->driver->recoverStaleProcessing('default', 60, 1));

        $methods = $this->callMethods();
        $this->assertContains('lrange', $methods);
        $this->assertSame(['zscore'], array_column($this->redis->pipelines[0]->calls, 'method'));
        $this->assertSame(['zadd'], array_column($this->redis->pipelines[1]->calls, 'method'));
    }

    public function testStaleRecoveryPipelinesScoreChecksWithoutUnneededWrites(): void
    {
        $this->redis->returns['lrange'] = ['123', '456'];
        $this->redis->pipelineReturns = [['1700000000', '1700000001']];
        $this->redis->returns['evalsha'] = 0;

        $this->assertSame(0, $this->driver->recoverStaleProcessing('default', 60, 2));

        $this->assertCount(1, $this->redis->pipelines);
        $this->assertSame(['zscore', 'zscore'], array_column($this->redis->pipelines[0]->calls, 'method'));
    }

    public function testAckRemovesFromProcessingListAndZset(): void
    {
        $this->driver->ack('default', 123);

        $this->assertNotNull($this->redis->pipeline);
        $this->assertTrue($this->redis->pipeline->executed);

        $pipelineMethods = array_column($this->redis->pipeline->calls, 'method');
        $this->assertContains('lrem', $pipelineMethods, 'Should remove from processing list');
        $this->assertContains('zrem', $pipelineMethods, 'Should remove from processing ZSET');
    }

    public function testNackWithDelayAddsToDelayedZset(): void
    {
        $this->driver->nack('default', 123, 60);

        $this->assertNotNull($this->redis->pipeline);
        $this->assertTrue($this->redis->pipeline->executed);

        $pipelineMethods = array_column($this->redis->pipeline->calls, 'method');
        $this->assertContains('lrem', $pipelineMethods);
        $this->assertContains('zrem', $pipelineMethods);
        $this->assertContains('zadd', $pipelineMethods, 'Should add to delayed ZSET');
        $this->assertNotContains('lpush', $pipelineMethods, 'Should not immediately re-enqueue');

        $zaddCall = array_filter($this->redis->pipeline->calls, fn($c) => $c['method'] === 'zadd');
        $zaddCall = reset($zaddCall);
        $this->assertStringContainsString('delayed', $zaddCall['args'][0]);
    }

    public function testNackWithoutDelayReenqueuesImmediately(): void
    {
        $this->driver->nack('default', 123, 0);

        $this->assertNotNull($this->redis->pipeline);
        $this->assertTrue($this->redis->pipeline->executed);

        $pipelineMethods = array_column($this->redis->pipeline->calls, 'method');
        $this->assertContains('lrem', $pipelineMethods);
        $this->assertContains('zrem', $pipelineMethods);
        $this->assertContains('lpush', $pipelineMethods, 'Should immediately re-enqueue');
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

        $this->assertSame(3, $this->driver->promoteDelayedJobs('default', 50));

        $this->assertScriptInvocation(
            $coldCache ? 'eval' : 'evalsha',
            !$coldCache,
            2,
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

    public function testEnqueueDelayedBatchSendsSingleZadd(): void
    {
        $this->driver->enqueueDelayedBatch('default', [1, 2, 3], 1_700_000_100);

        $zaddCalls = $this->callsFor('zadd');
        $this->assertCount(1, $zaddCalls);

        $call = $zaddCalls[0];
        $this->assertEquals('test:queue:default:delayed', $call['args'][0]);
        $this->assertEquals([1 => 1_700_000_100, 2 => 1_700_000_100, 3 => 1_700_000_100], $call['args'][1]);
    }

    public function testEnqueueDelayedBatchEmptyDoesNothing(): void
    {
        $this->driver->enqueueDelayedBatch('default', [], 1_700_000_100);

        $this->assertCount(0, $this->callsFor('zadd'));
    }

    public function testRecoverStaleProcessingUsesCachedLuaScript(): void
    {
        $this->redis->returns['evalsha'] = 2;
        $this->redis->returns['lrange'] = [];

        $this->assertSame(2, $this->driver->recoverStaleProcessing('default', 600, 75));

        $this->assertScriptInvocation(
            'evalsha',
            true,
            3,
            ['test:queue:default:processing_z', 'test:queue:default:processing', 'test:queue:default:pending'],
            '75'
        );
    }

    /**
     * Assert a single Lua script invocation's key and argument shape.
     *
     * @param string $transportMethod Command used to run the script (evalsha or eval)
     * @param bool $expectSha True when the first argument must be a 40-character SHA1 digest
     * @param int $numKeys Number of Redis keys passed to the script
     * @param list<string> $keys Expected script keys in order
     * @param string $lastArgument Expected final non-key script argument
     */
    private function assertScriptInvocation(
        string $transportMethod,
        bool $expectSha,
        int $numKeys,
        array $keys,
        string $lastArgument
    ): void {
        $transportCalls = $this->callsFor($transportMethod);
        $this->assertCount(1, $transportCalls);
        $call = $transportCalls[0];
        if ($expectSha) {
            $this->assertSame(40, strlen($call['args'][0])); // SHA1 digest
        } else {
            $this->assertStringContainsString('ZRANGEBYSCORE', $call['args'][0]);
        }
        $this->assertSame($numKeys, $call['args'][1]);
        foreach ($keys as $index => $key) {
            $this->assertSame($key, $call['args'][$index + 2]);
        }
        $this->assertSame($lastArgument, $call['args'][$numKeys + 3]);
    }

    public function testClearRemovesAllKeys(): void
    {
        $this->driver->clear('default');

        $delCalls = $this->callsFor('del');
        $this->assertCount(1, $delCalls);

        $keys = $delCalls[0]['args'][0];
        $this->assertCount(4, $keys);
        $this->assertContains('test:queue:default:pending', $keys);
        $this->assertContains('test:queue:default:processing', $keys);
        $this->assertContains('test:queue:default:processing_z', $keys);
        $this->assertContains('test:queue:default:delayed', $keys);
    }

    public function testGetDelayedCount(): void
    {
        $this->redis->returns['zcard'] = 5;

        $count = $this->driver->getDelayedCount('default');

        $this->assertEquals(5, $count);
    }

    public function testEnqueueBatchUsesSingleLpush(): void
    {
        $this->driver->enqueueBatch('default', [1, 2, 3]);

        $lpushCalls = $this->callsFor('lpush');
        $this->assertCount(1, $lpushCalls);

        $call = $lpushCalls[0];
        $this->assertEquals('test:queue:default:pending', $call['args'][0]);
        $this->assertEquals(['1', '2', '3'], $call['args'][1]);
    }

    public function testEnqueueBatchEmptyArrayDoesNothing(): void
    {
        $this->driver->enqueueBatch('default', []);

        $this->assertCount(0, $this->callsFor('lpush'));
    }

    public function testGetPendingCount(): void
    {
        $this->redis->returns['llen'] = 10;

        $count = $this->driver->getPendingCount('default');

        $this->assertEquals(10, $count);
    }

    public function testGetProcessingCount(): void
    {
        $this->redis->returns['llen'] = 3;

        $count = $this->driver->getProcessingCount('default');

        $this->assertEquals(3, $count);
    }

    public function testIsAvailableReturnsTrueOnSuccessfulPing(): void
    {
        $this->redis->returns['ping'] = 'PONG';

        $this->assertTrue($this->driver->isAvailable());
    }

    public function testEnqueueAddsToCorrectKey(): void
    {
        $this->driver->enqueue('myqueue', 42);

        $lpushCalls = $this->callsFor('lpush');

        $this->assertEquals('test:queue:myqueue:pending', $lpushCalls[0]['args'][0]);
    }

    public function testValidateTimeoutThrowsExceptionWhenUnsafe(): void
    {
        $parameters = new MockRedisParameters(5);
        $connection = new MockRedisConnection($parameters);
        $this->redis->connection = $connection;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsafe timeout configuration');

        $this->driver->validateTimeout(5);
    }

    public function testValidateTimeoutAllowsSafeTimeouts(): void
    {
        $parameters = new MockRedisParameters(60);
        $connection = new MockRedisConnection($parameters);
        $this->redis->connection = $connection;

        // Should not throw exception
        $this->driver->validateTimeout(5);
        $this->assertTrue(true);
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

        $driver->validateTimeout(5);
        $this->assertTrue(true);
    }
}

class MockRedisConnection
{
    public function __construct(public $parameters = null)
    {
    }

    public function getParameters()
    {
        return $this->parameters;
    }
}

class MockRedisParameters
{
    public function __construct(public $read_write_timeout)
    {
    }
}
