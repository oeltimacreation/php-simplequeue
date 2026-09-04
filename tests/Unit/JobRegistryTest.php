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

        self::assertTrue($this->registry->has('test.job'));
    }

    public function testHasReturnsFalseForUnregisteredType(): void
    {
        self::assertFalse($this->registry->has('unknown.job'));
    }

    public function testGetReturnsHandlerInstance(): void
    {
        $this->registry->register('test.job', TestJobHandler::class);

        $handler = $this->registry->get('test.job');

        self::assertInstanceOf(TestJobHandler::class, $handler);
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

        // Reflection bypasses the class-string PHPDoc to verify the public runtime guard.
        (new \ReflectionMethod($this->registry, 'register'))->invoke(
            $this->registry,
            'invalid.job',
            \stdClass::class
        );
    }

    public function testGetRegisteredTypesReturnsAllTypes(): void
    {
        $this->registry->register('job.one', TestJobHandler::class);
        $this->registry->register('job.two', TestJobHandler::class);
        $this->registry->register('job.three', TestJobHandler::class);

        $types = $this->registry->getRegisteredTypes();

        self::assertCount(3, $types);
        self::assertContains('job.one', $types);
        self::assertContains('job.two', $types);
        self::assertContains('job.three', $types);
    }

    public function testUnregisterRemovesHandler(): void
    {
        $this->registry->register('test.job', TestJobHandler::class);
        self::assertTrue($this->registry->has('test.job'));

        $this->registry->unregister('test.job');

        self::assertFalse($this->registry->has('test.job'));
    }

    public function testClearRemovesAllHandlers(): void
    {
        $this->registry->register('job.one', TestJobHandler::class);
        $this->registry->register('job.two', TestJobHandler::class);

        $this->registry->clear();

        self::assertEmpty($this->registry->getRegisteredTypes());
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
