<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Unit;

use Oeltima\SimpleQueue\Internal\WorkerProcessControl;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class WorkerProcessControlTest extends TestCase
{
    public function testDisabledLockAlwaysAcquiresAndReleasesSafely(): void
    {
        $control = new WorkerProcessControl(new NullLogger(), null, 'worker-1');

        self::assertTrue($control->acquireLock());
        $control->releaseLock();
        $control->releaseLock();
    }

    public function testLockIsExclusiveAndReusableAfterRelease(): void
    {
        $directory = $this->temporaryDirectoryPath();
        $lockFile = $directory . '/worker.lock';
        $first = new WorkerProcessControl(new NullLogger(), $lockFile, 'worker-1');
        $second = new WorkerProcessControl(new NullLogger(), $lockFile, 'worker-2');

        try {
            self::assertTrue($first->acquireLock());
            self::assertSame('worker-1', file_get_contents($lockFile));
            self::assertSame(0700, fileperms($directory) & 0777);
            self::assertSame(0600, fileperms($lockFile) & 0777);
            self::assertFalse($second->acquireLock());

            $first->releaseLock();
            self::assertTrue($second->acquireLock());
            self::assertSame('worker-2', file_get_contents($lockFile));
        } finally {
            $first->releaseLock();
            $second->releaseLock();
            if (is_file($lockFile)) {
                unlink($lockFile);
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    public function testNonRegularLockPathIsRejected(): void
    {
        $directory = $this->temporaryDirectoryPath();
        self::assertTrue(mkdir($directory, 0700));
        $lockDirectory = $directory . '/worker.lock';
        self::assertTrue(mkdir($lockDirectory, 0700));

        try {
            $control = new WorkerProcessControl(new NullLogger(), $lockDirectory, 'worker-1');
            self::assertFalse($control->acquireLock());
        } finally {
            rmdir($lockDirectory);
            rmdir($directory);
        }
    }

    public function testSymbolicLinkLockPathIsRejectedWithoutChangingTarget(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Symbolic-link lock policy is Unix-specific');
        }
        $directory = $this->temporaryDirectoryPath();
        self::assertTrue(mkdir($directory, 0700));
        $target = $directory . '/target';
        $lockFile = $directory . '/worker.lock';
        self::assertSame(6, file_put_contents($target, 'target'));
        self::assertTrue(symlink($target, $lockFile));

        try {
            $control = new WorkerProcessControl(new NullLogger(), $lockFile, 'worker-1');
            self::assertFalse($control->acquireLock());
            self::assertSame('target', file_get_contents($target));
        } finally {
            unlink($lockFile);
            unlink($target);
            rmdir($directory);
        }
    }

    public function testExistingDirectoryPermissionsAreTightened(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Unix permission policy is unavailable');
        }
        $directory = $this->temporaryDirectoryPath();
        self::assertTrue(mkdir($directory, 0755));
        $lockFile = $directory . '/worker.lock';
        $control = new WorkerProcessControl(new NullLogger(), $lockFile, 'worker-1');

        try {
            self::assertTrue($control->acquireLock());
            clearstatcache(true, $directory);
            self::assertSame(0700, fileperms($directory) & 0777);
        } finally {
            $control->releaseLock();
            unlink($lockFile);
            rmdir($directory);
        }
    }

    public function testSignalHandlersAreRestoredAfterRegistration(): void
    {
        if (!function_exists('pcntl_signal_get_handler')) {
            self::markTestSkipped('pcntl signal inspection is unavailable');
        }

        $previousAsync = pcntl_async_signals();
        $previousTerm = pcntl_signal_get_handler(SIGTERM);
        $previousInt = pcntl_signal_get_handler(SIGINT);
        $control = new WorkerProcessControl(new NullLogger(), null, 'worker-1');

        try {
            $control->registerSignalHandlers(static function (int $signal): void {
            });

            self::assertTrue(pcntl_async_signals());
            self::assertIsCallable(pcntl_signal_get_handler(SIGTERM));
            self::assertIsCallable(pcntl_signal_get_handler(SIGINT));

            $control->restoreSignalHandlers();

            self::assertSame($previousAsync, pcntl_async_signals());
            self::assertSame($previousTerm, pcntl_signal_get_handler(SIGTERM));
            self::assertSame($previousInt, pcntl_signal_get_handler(SIGINT));
        } finally {
            pcntl_signal(SIGTERM, $previousTerm);
            pcntl_signal(SIGINT, $previousInt);
            pcntl_async_signals($previousAsync);
        }
    }

    private function temporaryDirectoryPath(): string
    {
        return sys_get_temp_dir() . '/simplequeue-process-control-' . bin2hex(random_bytes(8));
    }
}
