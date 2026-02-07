<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Unit;

use Oeltima\SimpleQueue\Driver\RedisQueueDriver;
use PHPUnit\Framework\TestCase;
use Predis\ClientInterface;
use Predis\Command\CommandInterface;

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
    
    public ?MockRedisPipeline $pipeline = null;

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

    public function getConnection()
    {
        return null;
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
        return $this->returns[$commandID] ?? null;
    }

    public function pipeline()
    {
        $this->pipeline = new MockRedisPipeline();
        return $this->pipeline;
    }
}

class MockRedisPipeline
{
    /** @var array<string, mixed> */
    public array $calls = [];
    public bool $executed = false;

    public function __call($method, $arguments)
    {
        $this->calls[] = ['method' => $method, 'args' => $arguments];
        return $this;
    }

    public function execute(): array
    {
        $this->executed = true;
        return [];
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

    public function testDequeueNonBlockingWhenTimeoutZero(): void
    {
        $this->redis->returns['rpoplpush'] = '123';

        $jobId = $this->driver->dequeue('default', 0);

        $this->assertEquals(123, $jobId);
        
        $callMethods = array_column($this->redis->calls, 'method');
        $this->assertContains('rpoplpush', $callMethods, 'Should use non-blocking rpoplpush');
        $this->assertNotContains('brpoplpush', $callMethods, 'Should not use blocking brpoplpush');
        $this->assertContains('zadd', $callMethods, 'Should track in processing ZSET');
    }

    public function testDequeueBlockingWhenTimeoutPositive(): void
    {
        $this->redis->returns['brpoplpush'] = '456';

        $jobId = $this->driver->dequeue('default', 5);

        $this->assertEquals(456, $jobId);
        
        $callMethods = array_column($this->redis->calls, 'method');
        $this->assertContains('brpoplpush', $callMethods, 'Should use blocking brpoplpush');
        $this->assertNotContains('rpoplpush', $callMethods, 'Should not use non-blocking rpoplpush');
    }

    public function testDequeueReturnsNullWhenEmpty(): void
    {
        $this->redis->returns['rpoplpush'] = null;

        $jobId = $this->driver->dequeue('default', 0);

        $this->assertNull($jobId);
    }

    public function testAckRemovesFromProcessingListAndZset(): void
    {
        $this->driver->ack('default', 123);

        $callMethods = array_column($this->redis->calls, 'method');
        $this->assertContains('lrem', $callMethods, 'Should remove from processing list');
        $this->assertContains('zrem', $callMethods, 'Should remove from processing ZSET');
    }

    public function testNackWithDelayAddsToDelayedZset(): void
    {
        $this->driver->nack('default', 123, 60);

        $callMethods = array_column($this->redis->calls, 'method');
        $this->assertContains('lrem', $callMethods);
        $this->assertContains('zrem', $callMethods);
        $this->assertContains('zadd', $callMethods, 'Should add to delayed ZSET');
        $this->assertNotContains('lpush', $callMethods, 'Should not immediately re-enqueue');

        $zaddCall = array_filter($this->redis->calls, fn($c) => $c['method'] === 'zadd');
        $zaddCall = reset($zaddCall);
        $this->assertStringContainsString('delayed', $zaddCall['args'][0]);
    }

    public function testNackWithoutDelayReenqueuesImmediately(): void
    {
        $this->driver->nack('default', 123, 0);

        $callMethods = array_column($this->redis->calls, 'method');
        $this->assertContains('lrem', $callMethods);
        $this->assertContains('zrem', $callMethods);
        $this->assertContains('lpush', $callMethods, 'Should immediately re-enqueue');
    }

    public function testPromoteDelayedJobsMovesJobsToPending(): void
    {
        $this->redis->returns['zrangebyscore'] = ['100', '101', '102'];

        $count = $this->driver->promoteDelayedJobs('default');

        $this->assertEquals(3, $count);
        $this->assertNotNull($this->redis->pipeline);
        $this->assertTrue($this->redis->pipeline->executed);
        
        $pipelineMethods = array_column($this->redis->pipeline->calls, 'method');
        $this->assertCount(3, array_filter($pipelineMethods, fn($m) => $m === 'lpush'));
        $this->assertContains('zremrangebyscore', $pipelineMethods);
    }

    public function testPromoteDelayedJobsReturnsZeroWhenNoDueJobs(): void
    {
        $this->redis->returns['zrangebyscore'] = [];

        $count = $this->driver->promoteDelayedJobs('default');

        $this->assertEquals(0, $count);
        $this->assertNull($this->redis->pipeline, 'Pipeline should not be created when no jobs');
    }

    public function testRecoverStaleProcessingMovesStaleJobsBack(): void
    {
        $this->redis->returns['zrangebyscore'] = ['200', '201'];

        $count = $this->driver->recoverStaleProcessing('default', 600);

        $this->assertEquals(2, $count);
        $this->assertNotNull($this->redis->pipeline);
        $this->assertTrue($this->redis->pipeline->executed);

        $pipelineMethods = array_column($this->redis->pipeline->calls, 'method');
        $this->assertCount(2, array_filter($pipelineMethods, fn($m) => $m === 'lrem'));
        $this->assertCount(2, array_filter($pipelineMethods, fn($m) => $m === 'lpush'));
        $this->assertContains('zremrangebyscore', $pipelineMethods);
    }

    public function testRecoverStaleProcessingReturnsZeroWhenNoStaleJobs(): void
    {
        $this->redis->returns['zrangebyscore'] = [];

        $count = $this->driver->recoverStaleProcessing('default', 600);

        $this->assertEquals(0, $count);
        $this->assertNull($this->redis->pipeline);
    }

    public function testClearRemovesAllKeys(): void
    {
        $this->driver->clear('default');

        $delCall = array_filter($this->redis->calls, fn($c) => $c['method'] === 'del');
        $this->assertCount(1, $delCall);
        
        $delCall = reset($delCall);
        $keys = $delCall['args'][0];
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

    public function testEnqueueBatchUsesPipeline(): void
    {
        $this->driver->enqueueBatch('default', [1, 2, 3]);

        $this->assertNotNull($this->redis->pipeline);
        $this->assertTrue($this->redis->pipeline->executed);

        $lpushCalls = array_filter(
            $this->redis->pipeline->calls,
            fn($c) => $c['method'] === 'lpush'
        );
        $this->assertCount(3, $lpushCalls);

        foreach ($lpushCalls as $call) {
            $this->assertEquals('test:queue:default:pending', $call['args'][0]);
        }
    }

    public function testEnqueueBatchEmptyArrayDoesNothing(): void
    {
        $this->driver->enqueueBatch('default', []);

        $this->assertNull($this->redis->pipeline);
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

        $lpushCall = array_filter(
            $this->redis->calls,
            fn($c) => $c['method'] === 'lpush'
        );
        $lpushCall = reset($lpushCall);

        $this->assertEquals('test:queue:myqueue:pending', $lpushCall['args'][0]);
    }
}
