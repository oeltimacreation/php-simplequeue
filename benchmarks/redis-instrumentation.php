<?php

declare(strict_types=1);

use Predis\ClientInterface;
use Predis\Command\CommandInterface;

final class BenchmarkRedisClient implements ClientInterface
{
    public int $commands = 0;
    public int $roundTrips = 0;
    public int $wireBytes = 0;

    public function __construct(public readonly ClientInterface $inner)
    {
    }

    public function getCommandFactory()
    {
        return $this->inner->getCommandFactory();
    }

    public function getOptions()
    {
        return $this->inner->getOptions();
    }

    public function connect(): void
    {
        $this->inner->connect();
    }

    public function disconnect(): void
    {
        $this->inner->disconnect();
    }

    public function getConnection()
    {
        return $this->inner->getConnection();
    }

    public function createCommand($method, $arguments = [])
    {
        return $this->inner->createCommand($method, $arguments);
    }

    public function executeCommand(CommandInterface $command)
    {
        $this->commands++;
        $this->roundTrips++;
        $this->wireBytes += $this->commandWireBytes($command->getId(), $command->getArguments());
        return $this->inner->executeCommand($command);
    }

    public function __call($method, $arguments)
    {
        if ($method === 'pipeline') {
            return new BenchmarkRedisPipeline($this, $this->inner->pipeline(...$arguments));
        }
        $this->commands++;
        $this->roundTrips++;
        $this->wireBytes += $this->commandWireBytes($method, $arguments);
        return $this->inner->{$method}(...$arguments);
    }

    public function resetCounts(): void
    {
        $this->commands = 0;
        $this->roundTrips = 0;
        $this->wireBytes = 0;
    }

    /**
     * Estimate the wire payload bytes of a Lua script invocation.
     *
     * EVALSHA transmits the 40-byte digest while EVAL transmits the full script
     * body, so this tracks the first argument of both command shapes.
     *
     * @param string $method Command name
     * @param array<int, mixed> $arguments Command arguments
     */
    private function commandWireBytes(string $method, array $arguments): int
    {
        if (!in_array(strtoupper($method), ['EVAL', 'EVALSHA'], true)) {
            return 0;
        }
        return isset($arguments[0]) && is_scalar($arguments[0]) ? strlen((string) $arguments[0]) : 0;
    }
}

final class BenchmarkRedisPipeline
{
    public function __construct(
        private readonly BenchmarkRedisClient $client,
        private readonly object $pipeline
    ) {
    }

    public function __call(string $method, array $arguments): self
    {
        $this->client->commands++;
        $this->pipeline->{$method}(...$arguments);
        return $this;
    }

    public function execute(): array
    {
        $this->client->roundTrips++;
        return $this->pipeline->execute();
    }
}
