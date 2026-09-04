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
        if ($this->lockingIsUnavailable()) {
            $this->logger->warning('Locking disabled - unsafe for production, dev use only');
            return true;
        }

        if (!$this->prepareLockPath()) {
            return false;
        }
        $lockFile = $this->lockFile;
        if ($lockFile === null) {
            return false;
        }
        $handle = $this->openPrivateLock();
        if ($handle === false) {
            return false;
        }
        if (!$this->validateOpenedLock($handle, $lockFile)) {
            fclose($handle);
            return false;
        }
        $this->lockHandle = $handle;
        return $this->lockAndIdentify($handle);
    }

    private function lockingIsUnavailable(): bool
    {
        if ($this->lockFile === null) {
            return true;
        }
        return PHP_OS_FAMILY === 'Windows';
    }

    private function prepareLockPath(): bool
    {
        if ($this->lockFile === null) {
            return false;
        }
        if (!$this->ensurePrivateDirectory(dirname($this->lockFile))) {
            return false;
        }
        if ($this->existingLockPathIsSafe()) {
            return true;
        }
        $this->logger->error('Lock path is not a regular file');
        return false;
    }

    private function existingLockPathIsSafe(): bool
    {
        if ($this->lockFile === null) {
            return false;
        }
        if (!file_exists($this->lockFile)) {
            return !is_link($this->lockFile);
        }
        if (!is_link($this->lockFile)) {
            return $this->isSafeExistingLockPath();
        }
        return false;
    }

    /** @return resource|false */
    private function openPrivateLock()
    {
        if ($this->lockFile === null) {
            return false;
        }
        $oldUmask = umask(0077);
        try {
            return fopen($this->lockFile, 'c');
        } finally {
            umask($oldUmask);
        }
    }

    /** @param resource $handle */
    private function lockAndIdentify($handle): bool
    {
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            $this->closeLock($handle);
            return false;
        }
        if ($this->writeWorkerIdentity($handle)) {
            return true;
        }
        flock($handle, LOCK_UN);
        $this->closeLock($handle);
        $this->logger->error('Failed to write worker identity to lock file');
        return false;
    }

    /** @param resource $handle */
    private function writeWorkerIdentity($handle): bool
    {
        if (!ftruncate($handle, 0)) {
            return false;
        }
        $bytes = fwrite($handle, $this->workerId);
        return $bytes === strlen($this->workerId) && fflush($handle);
    }

    /** @param resource $handle */
    private function closeLock($handle): void
    {
        fclose($handle);
        $this->lockHandle = null;
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
        if (!$this->createPrivateDirectory($dir)) {
            return false;
        }

        $realDir = realpath($dir);
        if ($realDir === false || !is_dir($realDir)) {
            return false;
        }
        if (!$this->directoryIsOwned($realDir)) {
            $this->logger->error('Lock directory is not owned by the current user');
            return false;
        }
        return $this->secureDirectoryPermissions($realDir);
    }

    private function createPrivateDirectory(string $dir): bool
    {
        if (is_dir($dir)) {
            return true;
        }
        $oldUmask = umask(0077);
        try {
            if (mkdir($dir, 0700, true)) {
                return true;
            }
            return is_dir($dir);
        } finally {
            umask($oldUmask);
        }
    }

    private function directoryIsOwned(string $dir): bool
    {
        if (!function_exists('posix_geteuid')) {
            return true;
        }
        return @fileowner($dir) === posix_geteuid();
    }

    private function secureDirectoryPermissions(string $dir): bool
    {
        $perms = @fileperms($dir);
        if ($perms === false) {
            return $this->directoryPermissionsArePrivate($perms);
        }
        if (($perms & 0777) === 0700) {
            return true;
        }
        return $this->chmodPrivateDirectory($dir);
    }

    private function chmodPrivateDirectory(string $dir): bool
    {
        if (!@chmod($dir, 0700)) {
            $this->logger->error('Lock directory permissions could not be secured');
            return false;
        }
        clearstatcache(true, $dir);
        return $this->directoryPermissionsArePrivate(@fileperms($dir));
    }

    private function directoryPermissionsArePrivate(int|false $perms): bool
    {
        if ($perms === false) {
            $this->logger->error('Lock directory permissions are not private');
            return false;
        }
        if (($perms & 0777) !== 0700) {
            $this->logger->error('Lock directory permissions are not private');
            return false;
        }
        return true;
    }

    private function isSafeExistingLockPath(): bool
    {
        if ($this->lockFile === null) {
            return false;
        }
        if (is_link($this->lockFile)) {
            return false;
        }
        if (!is_file($this->lockFile)) {
            return false;
        }
        if ($this->lockFileIsOwned()) {
            return true;
        }
        $this->logger->error('Lock file is not owned by the current user');
        return false;
    }

    private function lockFileIsOwned(): bool
    {
        if ($this->lockFile === null) {
            return true;
        }
        if (!function_exists('posix_geteuid')) {
            return true;
        }
        return @fileowner($this->lockFile) === posix_geteuid();
    }

    /** @param resource $handle Open lock-file handle */
    private function validateOpenedLock($handle, string $lockFile): bool
    {
        $opened = fstat($handle);
        clearstatcache(true, $lockFile);
        $path = @lstat($lockFile);
        if ($opened === false) {
            $this->logger->error('Opened lock path is not a regular file');
            return false;
        }
        if ($path === false) {
            $this->logger->error('Opened lock path is not a regular file');
            return false;
        }
        if (!$this->openedPathIsRegular($opened, $path)) {
            $this->logger->error('Opened lock path is not a regular file');
            return false;
        }
        if (!$this->sameFile($opened, $path)) {
            $this->logger->error('Lock path changed while it was being opened');
            return false;
        }
        if (!$this->openedFileIsOwned($opened)) {
            $this->logger->error('Opened lock file is not owned by the current user');
            return false;
        }
        if (!@chmod($lockFile, 0600)) {
            $this->logger->error('Lock file permissions could not be secured');
            return false;
        }
        return $this->validateSecuredLock($handle, $lockFile);
    }

    /**
     * @param array<int|string, int> $opened
     * @param array<int|string, int> $path
     */
    private function openedPathIsRegular(array $opened, array $path): bool
    {
        return ($opened['mode'] & 0170000) === 0100000;
    }

    /**
     * @param array<int|string, int> $opened
     * @param array<int|string, int> $path
     */
    private function sameFile(array $opened, array $path): bool
    {
        return $opened['dev'] === $path['dev'] && $opened['ino'] === $path['ino'];
    }

    /** @param array<int|string, int> $opened */
    private function openedFileIsOwned(array $opened): bool
    {
        if (!function_exists('posix_geteuid')) {
            return true;
        }
        return $opened['uid'] === posix_geteuid();
    }

    /** @param resource $handle */
    private function validateSecuredLock($handle, string $lockFile): bool
    {
        clearstatcache(true, $lockFile);
        $secured = fstat($handle);
        $path = @lstat($lockFile);
        if ($secured === false) {
            $this->logger->error('Lock file permissions are not private');
            return false;
        }
        if ($path === false) {
            $this->logger->error('Lock file permissions are not private');
            return false;
        }
        if ($this->securedLockIsPrivate($secured, $path)) {
            return true;
        }
        $this->logger->error('Lock file permissions are not private');
        return false;
    }

    /**
     * @param array<int|string, int> $secured
     * @param array<int|string, int> $path
     */
    private function securedLockIsPrivate(array $secured, array $path): bool
    {
        if (!$this->sameFile($secured, $path)) {
            return false;
        }
        return ($secured['mode'] & 0777) === 0600;
    }

    private function restoreSignal(int $signal, mixed $handler): void
    {
        if ($handler === null) {
            return;
        }
        if (is_callable($handler)) {
            pcntl_signal($signal, $handler);
            return;
        }
        if (is_int($handler)) {
            pcntl_signal($signal, $handler);
        }
    }
}
