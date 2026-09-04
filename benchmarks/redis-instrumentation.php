<?php

declare(strict_types=1);

use Predis\Client;
use Predis\ClientInterface;
use Predis\Command\CommandInterface;
use Predis\Command\FactoryInterface;
use Predis\Configuration\OptionsInterface;
use Predis\Connection\ConnectionInterface;
use Predis\Pipeline\Pipeline;

final class BenchmarkRedisClient implements ClientInterface
{
    public int $commands = 0;
    public int $roundTrips = 0;
    public int $wireBytes = 0;

    public function __construct(public readonly Client $inner)
    {
    }

    public function getCommandFactory(): FactoryInterface
    {
        return $this->inner->getCommandFactory();
    }

    public function getOptions(): OptionsInterface
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

    public function getConnection(): ConnectionInterface
    {
        return $this->inner->getConnection();
    }

    /** @param array<array-key, mixed> $arguments Command arguments */
    public function createCommand(mixed $method, mixed $arguments = []): CommandInterface
    {
        return $this->inner->createCommand($method, $arguments);
    }

    public function executeCommand(CommandInterface $command): mixed
    {
        $this->commands++;
        $this->roundTrips++;
        $this->wireBytes += $this->commandWireBytes($command->getId(), $command->getArguments());
        return $this->inner->executeCommand($command);
    }

    /** @param array<array-key, mixed> $arguments Command arguments */
    public function __call(mixed $method, mixed $arguments): mixed
    {
        if ($method === 'pipeline') {
            $pipeline = $this->inner->pipeline(...$arguments);
            if (!$pipeline instanceof Pipeline) {
                throw new UnexpectedValueException('Predis pipeline call did not return a pipeline');
            }
            return new BenchmarkRedisPipeline($this, $pipeline);
        }
        $this->commands++;
        $this->roundTrips++;
        $this->wireBytes += $this->commandWireBytes($method, $arguments);
        return $this->inner->__call($method, $arguments);
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
     * @param array<array-key, mixed> $arguments Command arguments
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
        private readonly Pipeline $pipeline
    ) {
    }

    /** @param array<int, mixed> $arguments Command arguments */
    public function __call(string $method, array $arguments): self
    {
        $this->client->commands++;
        $this->pipeline->__call($method, $arguments);
        return $this;
    }

    /** @return array<array-key, mixed> Pipeline responses */
    public function execute(): array
    {
        $this->client->roundTrips++;
        return $this->pipeline->execute();
    }
}
