<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue;

use Oeltima\SimpleQueue\Contract\JobHandlerInterface;
use Oeltima\SimpleQueue\Exception\HandlerNotFoundException;
use Psr\Container\ContainerInterface;

/**
 * Registry for job handlers.
 *
 * Maps job types to their handler classes and provides
 * handler instantiation through a PSR-11 container.
 */
final class JobRegistry
{
    /** @var array<string, class-string<JobHandlerInterface>> */
    private array $handlers = [];

    private ?ContainerInterface $container;

    /**
     * @param ContainerInterface|null $container Optional PSR-11 container for handler resolution
     */
    public function __construct(?ContainerInterface $container = null)
    {
        $this->container = $container;
    }

    /**
     * Register a job type with its handler class.
     *
     * @param string $type Job type identifier (e.g., 'email.send', 'report.generate')
     * @param class-string<JobHandlerInterface> $handlerClass Handler class name
     * @throws \InvalidArgumentException If handler class doesn't implement JobHandlerInterface
     */
    public function register(string $type, string $handlerClass): void
    {
        if (!is_subclass_of($handlerClass, JobHandlerInterface::class)) {
            throw new \InvalidArgumentException(
                sprintf('Handler class %s must implement JobHandlerInterface', $handlerClass)
            );
        }
        $this->handlers[$type] = $handlerClass;
    }

    /**
     * Check if a handler is registered for the given type.
     *
     * @param string $type Job type identifier
     * @return bool True if a handler is registered
     */
    public function has(string $type): bool
    {
        return isset($this->handlers[$type]);
    }

    /**
     * Get the handler instance for a job type.
     *
     * If a container is configured and has the handler class registered,
     * the handler will be resolved from the container. Otherwise, a new
     * instance is created directly.
     *
     * @param string $type Job type identifier
     * @return JobHandlerInterface Handler instance
     * @throws \RuntimeException If no handler is registered for the type
     */
    public function get(string $type): JobHandlerInterface
    {
        if (!$this->has($type)) {
            throw HandlerNotFoundException::forType($type);
        }

        $handlerClass = $this->handlers[$type];

        // Try container resolution first
        if ($this->container !== null && $this->container->has($handlerClass)) {
            $handler = $this->container->get($handlerClass);
            if ($handler instanceof JobHandlerInterface) {
                return $handler;
            }
        }

        // Direct instantiation
        return new $handlerClass();
    }

    /**
     * Get all registered job types.
     *
     * @return array<string> List of registered job type identifiers
     */
    public function getRegisteredTypes(): array
    {
        return array_keys($this->handlers);
    }

    /**
     * Remove a handler registration.
     *
     * @param string $type Job type identifier
     */
    public function unregister(string $type): void
    {
        unset($this->handlers[$type]);
    }

    /**
     * Clear all handler registrations.
     */
    public function clear(): void
    {
        $this->handlers = [];
    }
}
