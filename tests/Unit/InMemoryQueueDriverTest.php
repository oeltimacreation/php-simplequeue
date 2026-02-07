<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Unit;

use Oeltima\SimpleQueue\Driver\InMemoryQueueDriver;
use PHPUnit\Framework\TestCase;

class InMemoryQueueDriverTest extends TestCase
{
    private InMemoryQueueDriver $driver;

    protected function setUp(): void
    {
        $this->driver = new InMemoryQueueDriver();
    }

    public function testIsAvailableReturnsTrue(): void
    {
        $this->assertTrue($this->driver->isAvailable());
    }

    public function testEnqueueAddsJobToPending(): void
    {
        $this->driver->enqueue('default', 1);
        $this->driver->enqueue('default', 2);
        $this->driver->enqueue('default', 3);

        $pending = $this->driver->getPending('default');

        $this->assertCount(3, $pending);
        $this->assertContains(1, $pending);
        $this->assertContains(2, $pending);
        $this->assertContains(3, $pending);
    }

    public function testDequeueReturnsJobIdAndMovesToProcessing(): void
    {
        $this->driver->enqueue('default', 1);
        $this->driver->enqueue('default', 2);

        $jobId = $this->driver->dequeue('default', 0);

        // Should return first job (FIFO)
        $this->assertEquals(1, $jobId);

        // Should be in processing now
        $processing = $this->driver->getProcessing('default');
        $this->assertContains(1, $processing);

        // Should not be in pending anymore
        $pending = $this->driver->getPending('default');
        $this->assertNotContains(1, $pending);
    }

    public function testDequeueReturnsNullWhenEmpty(): void
    {
        $jobId = $this->driver->dequeue('default', 0);

        $this->assertNull($jobId);
    }

    public function testAckRemovesFromProcessing(): void
    {
        $this->driver->enqueue('default', 1);
        $this->driver->dequeue('default', 0);

        $this->driver->ack('default', 1);

        $processing = $this->driver->getProcessing('default');
        $this->assertNotContains(1, $processing);
    }

    public function testNackMovesBackToPending(): void
    {
        $this->driver->enqueue('default', 1);
        $this->driver->dequeue('default', 0);

        $this->driver->nack('default', 1);

        // Should not be in processing
        $processing = $this->driver->getProcessing('default');
        $this->assertNotContains(1, $processing);

        // Should be back in pending
        $pending = $this->driver->getPending('default');
        $this->assertContains(1, $pending);
    }

    public function testClearRemovesAllQueues(): void
    {
        $this->driver->enqueue('queue1', 1);
        $this->driver->enqueue('queue2', 2);
        $this->driver->dequeue('queue1', 0);

        $this->driver->clear();

        $this->assertEmpty($this->driver->getPending('queue1'));
        $this->assertEmpty($this->driver->getPending('queue2'));
        $this->assertEmpty($this->driver->getProcessing('queue1'));
    }

    public function testQueuesAreIsolated(): void
    {
        $this->driver->enqueue('queue1', 1);
        $this->driver->enqueue('queue2', 2);

        $pending1 = $this->driver->getPending('queue1');
        $pending2 = $this->driver->getPending('queue2');

        $this->assertContains(1, $pending1);
        $this->assertNotContains(2, $pending1);

        $this->assertContains(2, $pending2);
        $this->assertNotContains(1, $pending2);
    }

    public function testNackWithDelayAddsToDelayed(): void
    {
        $this->driver->enqueue('default', 1);
        $this->driver->dequeue('default', 0);

        $this->driver->nack('default', 1, 60);

        $pending = $this->driver->getPending('default');
        $this->assertNotContains(1, $pending);

        $delayed = $this->driver->getDelayed('default');
        $this->assertArrayHasKey(1, $delayed);
    }

    public function testNackWithoutDelayReenqueuesImmediately(): void
    {
        $this->driver->enqueue('default', 1);
        $this->driver->dequeue('default', 0);

        $this->driver->nack('default', 1, 0);

        $pending = $this->driver->getPending('default');
        $this->assertContains(1, $pending);

        $delayed = $this->driver->getDelayed('default');
        $this->assertEmpty($delayed);
    }

    public function testPromoteDelayedJobsMovesToPending(): void
    {
        $this->driver->enqueue('default', 1);
        $this->driver->dequeue('default', 0);
        $this->driver->nack('default', 1, 60);

        $ref = new \ReflectionClass($this->driver);
        $prop = $ref->getProperty('delayed');
        $delayed = $prop->getValue($this->driver);
        $delayed['default'][1] = time() - 10;
        $prop->setValue($this->driver, $delayed);

        $count = $this->driver->promoteDelayedJobs('default');

        $this->assertEquals(1, $count);
        $this->assertContains(1, $this->driver->getPending('default'));
        $this->assertEmpty($this->driver->getDelayed('default'));
    }

    public function testPromoteDelayedJobsIgnoresNotYetDue(): void
    {
        $this->driver->enqueue('default', 1);
        $this->driver->dequeue('default', 0);
        $this->driver->nack('default', 1, 3600);

        $count = $this->driver->promoteDelayedJobs('default');

        $this->assertEquals(0, $count);
        $this->assertNotContains(1, $this->driver->getPending('default'));
        $this->assertArrayHasKey(1, $this->driver->getDelayed('default'));
    }

    public function testRecoverStaleProcessingMovesBackToPending(): void
    {
        $this->driver->enqueue('default', 1);
        $this->driver->dequeue('default', 0);

        $ref = new \ReflectionClass($this->driver);
        $prop = $ref->getProperty('processingStartedAt');
        $startedAt = $prop->getValue($this->driver);
        $startedAt['default'][1] = time() - 700;
        $prop->setValue($this->driver, $startedAt);

        $count = $this->driver->recoverStaleProcessing('default', 600);

        $this->assertEquals(1, $count);
        $this->assertContains(1, $this->driver->getPending('default'));
        $this->assertEmpty($this->driver->getProcessing('default'));
    }

    public function testRecoverStaleProcessingIgnoresRecentJobs(): void
    {
        $this->driver->enqueue('default', 1);
        $this->driver->dequeue('default', 0);

        $count = $this->driver->recoverStaleProcessing('default', 600);

        $this->assertEquals(0, $count);
        $this->assertContains(1, $this->driver->getProcessing('default'));
    }

    public function testClearResetsDelayedAndProcessingStartedAt(): void
    {
        $this->driver->enqueue('default', 1);
        $this->driver->dequeue('default', 0);
        $this->driver->nack('default', 1, 60);

        $this->driver->clear();

        $this->assertEmpty($this->driver->getPending('default'));
        $this->assertEmpty($this->driver->getProcessing('default'));
        $this->assertEmpty($this->driver->getDelayed('default'));
    }
}
