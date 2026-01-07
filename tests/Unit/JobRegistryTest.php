<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Unit;

use Oeltima\SimpleQueue\Contract\JobHandlerInterface;
use Oeltima\SimpleQueue\Exception\HandlerNotFoundException;
use Oeltima\SimpleQueue\JobRegistry;
use PHPUnit\Framework\TestCase;

class JobRegistryTest extends TestCase
{
    private JobRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new JobRegistry();
    }

    public function testRegisterAddsHandler(): void
    {
        $this->registry->register('test.job', TestJobHandler::class);

        $this->assertTrue($this->registry->has('test.job'));
    }

    public function testHasReturnsFalseForUnregisteredType(): void
    {
        $this->assertFalse($this->registry->has('unknown.job'));
    }

    public function testGetReturnsHandlerInstance(): void
    {
        $this->registry->register('test.job', TestJobHandler::class);

        $handler = $this->registry->get('test.job');

        $this->assertInstanceOf(JobHandlerInterface::class, $handler);
        $this->assertInstanceOf(TestJobHandler::class, $handler);
    }

    public function testGetThrowsExceptionForUnregisteredType(): void
    {
        $this->expectException(HandlerNotFoundException::class);
        $this->expectExceptionMessage('No handler registered for job type: unknown.job');

        $this->registry->get('unknown.job');
    }

    public function testRegisterThrowsExceptionForInvalidHandler(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must implement JobHandlerInterface');

        $this->registry->register('invalid.job', \stdClass::class);
    }

    public function testGetRegisteredTypesReturnsAllTypes(): void
    {
        $this->registry->register('job.one', TestJobHandler::class);
        $this->registry->register('job.two', TestJobHandler::class);
        $this->registry->register('job.three', TestJobHandler::class);

        $types = $this->registry->getRegisteredTypes();

        $this->assertCount(3, $types);
        $this->assertContains('job.one', $types);
        $this->assertContains('job.two', $types);
        $this->assertContains('job.three', $types);
    }

    public function testUnregisterRemovesHandler(): void
    {
        $this->registry->register('test.job', TestJobHandler::class);
        $this->assertTrue($this->registry->has('test.job'));

        $this->registry->unregister('test.job');

        $this->assertFalse($this->registry->has('test.job'));
    }

    public function testClearRemovesAllHandlers(): void
    {
        $this->registry->register('job.one', TestJobHandler::class);
        $this->registry->register('job.two', TestJobHandler::class);

        $this->registry->clear();

        $this->assertEmpty($this->registry->getRegisteredTypes());
    }
}

/**
 * Test job handler for unit tests.
 */
class TestJobHandler implements JobHandlerInterface
{
    public function handle(int $jobId, array $payload, ?callable $progressCallback = null): mixed
    {
        return ['processed' => true];
    }
}
