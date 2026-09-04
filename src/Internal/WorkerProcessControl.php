<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Internal;

use Psr\Log\LoggerInterface;

/**
 * Owns process-global signal state and the singleton worker lock.
 *
 * @internal
 */
final class WorkerProcessControl
{
    /** @var resource|null */
    private $lockHandle = null;
    private mixed $priorSigterm = null;
    private mixed $priorSigint = null;
    private ?bool $priorAsyncSignals = null;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ?string $lockFile,
        private readonly string $workerId
    ) {
    }

    /**
     * Acquire the configured singleton lock.
     */
    public function acquireLock(): bool
    {
        if ($this->lockFile === null) {
            $this->logger->warning('Locking disabled - unsafe for production, dev use only');
            return true;
        }
        if (PHP_OS_FAMILY === 'Windows') {
            $this->logger->warning('Locking disabled - unsafe for production, dev use only');
            return true;
        }

        $dir = dirname($this->lockFile);
        if (!$this->ensurePrivateDirectory($dir)) {
            return false;
        }
        if ((file_exists($this->lockFile) || is_link($this->lockFile)) && !$this->isSafeExistingLockPath()) {
            $this->logger->error('Lock path is not a regular file');
            return false;
        }

        $oldUmask = umask(0077);
        try {
            $handle = fopen($this->lockFile, 'c');
        } finally {
            umask($oldUmask);
        }
        if ($handle === false) {
            return false;
        }
        if (!$this->validateOpenedLock($handle, $this->lockFile)) {
            fclose($handle);
            return false;
        }
        $this->lockHandle = $handle;
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            $this->lockHandle = null;
            return false;
        }

        $written = false;
        if (ftruncate($handle, 0)) {
            $bytes = fwrite($handle, $this->workerId);
            $written = $bytes === strlen($this->workerId) && fflush($handle);
        }
        if (!$written) {
            flock($handle, LOCK_UN);
            fclose($handle);
            $this->lockHandle = null;
            $this->logger->error('Failed to write worker identity to lock file');
            return false;
        }
        return true;
    }

    /**
     * Release a held singleton lock.
     */
    public function releaseLock(): void
    {
        if ($this->lockHandle === null) {
            return;
        }
        flock($this->lockHandle, LOCK_UN);
        fclose($this->lockHandle);
        $this->lockHandle = null;
    }

    /**
     * Install graceful-shutdown handlers while preserving process-global state.
     *
     * @param callable(int): void $shutdown Signal callback
     */
    public function registerSignalHandlers(callable $shutdown): void
    {
        if (!function_exists('pcntl_signal')) {
            $this->logger->warning('pcntl extension not available, graceful shutdown may not work');
            return;
        }

        $this->priorAsyncSignals = pcntl_async_signals();
        if (function_exists('pcntl_signal_get_handler')) {
            $this->priorSigterm = pcntl_signal_get_handler(SIGTERM);
            $this->priorSigint = pcntl_signal_get_handler(SIGINT);
        }
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, $shutdown);
        pcntl_signal(SIGINT, $shutdown);
    }

    /**
     * Restore signal handlers and async-signal mode captured at registration.
     */
    public function restoreSignalHandlers(): void
    {
        if (!function_exists('pcntl_signal')) {
            return;
        }
        try {
            if (function_exists('pcntl_signal_get_handler')) {
                $this->restoreSignal(SIGTERM, $this->priorSigterm);
                $this->restoreSignal(SIGINT, $this->priorSigint);
            }
            if ($this->priorAsyncSignals !== null) {
                pcntl_async_signals($this->priorAsyncSignals);
            }
        } catch (\Throwable) {
            // Best effort: restoration must never mask the run outcome.
        } finally {
            $this->priorSigterm = null;
            $this->priorSigint = null;
            $this->priorAsyncSignals = null;
        }
    }

    private function ensurePrivateDirectory(string $dir): bool
    {
        if (is_link($dir)) {
            $this->logger->error('Lock directory must not be a symbolic link');
            return false;
        }
        if (!is_dir($dir)) {
            $oldUmask = umask(0077);
            try {
                if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
                    return false;
                }
            } finally {
                umask($oldUmask);
            }
        }

        $realDir = realpath($dir);
        if ($realDir === false || !is_dir($realDir)) {
            return false;
        }
        if (function_exists('posix_geteuid') && @fileowner($realDir) !== posix_geteuid()) {
            $this->logger->error('Lock directory is not owned by the current user');
            return false;
        }
        $perms = @fileperms($realDir);
        if ($perms !== false && ($perms & 0777) !== 0700) {
            if (!@chmod($realDir, 0700)) {
                $this->logger->error('Lock directory permissions could not be secured');
                return false;
            }
            clearstatcache(true, $realDir);
            $perms = @fileperms($realDir);
        }
        if ($perms === false || ($perms & 0777) !== 0700) {
            $this->logger->error('Lock directory permissions are not private');
            return false;
        }
        return true;
    }

    private function isSafeExistingLockPath(): bool
    {
        if ($this->lockFile === null || is_link($this->lockFile) || !is_file($this->lockFile)) {
            return false;
        }
        if (function_exists('posix_geteuid') && @fileowner($this->lockFile) !== posix_geteuid()) {
            $this->logger->error('Lock file is not owned by the current user');
            return false;
        }
        return true;
    }

    /** @param resource $handle Open lock-file handle */
    private function validateOpenedLock($handle, string $lockFile): bool
    {
        $opened = fstat($handle);
        clearstatcache(true, $lockFile);
        $path = @lstat($lockFile);
        if ($opened === false || $path === false || ($opened['mode'] & 0170000) !== 0100000) {
            $this->logger->error('Opened lock path is not a regular file');
            return false;
        }
        if ($opened['dev'] !== $path['dev'] || $opened['ino'] !== $path['ino']) {
            $this->logger->error('Lock path changed while it was being opened');
            return false;
        }
        if (function_exists('posix_geteuid') && $opened['uid'] !== posix_geteuid()) {
            $this->logger->error('Opened lock file is not owned by the current user');
            return false;
        }
        if (!@chmod($lockFile, 0600)) {
            $this->logger->error('Lock file permissions could not be secured');
            return false;
        }
        clearstatcache(true, $lockFile);
        $secured = fstat($handle);
        $securedPath = @lstat($lockFile);
        if (
            $secured === false
            || $securedPath === false
            || $secured['dev'] !== $securedPath['dev']
            || $secured['ino'] !== $securedPath['ino']
            || ($secured['mode'] & 0777) !== 0600
        ) {
            $this->logger->error('Lock file permissions are not private');
            return false;
        }
        return true;
    }

    private function restoreSignal(int $signal, mixed $handler): void
    {
        if ($handler !== null && (is_callable($handler) || is_int($handler))) {
            pcntl_signal($signal, $handler);
        }
    }
}
