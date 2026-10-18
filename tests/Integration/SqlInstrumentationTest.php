<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Integration;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/benchmarks/benchmark-types.php';

final class SqlInstrumentationTest extends TestCase
{
    public function testReusedStatementCountsEveryExecutionAndFetchedJsonBytes(): void
    {
        $pdo = new \BenchmarkPdo('sqlite::memory:');
        $statement = $pdo->prepare('SELECT :payload AS payload, :result AS result');
        self::assertNotFalse($statement);
        for ($index = 0; $index < 2; $index++) {
            $statement->execute(['payload' => '{"a":1}', 'result' => 'null']);
            $statement->fetch(\PDO::FETCH_ASSOC);
            $statement->closeCursor();
        }
        self::assertSame(1, $pdo->prepares);
        self::assertSame(2, $pdo->executions);
        self::assertSame(1, $pdo->queries);
        self::assertSame(22, $pdo->fetchedJsonBytes);
        $pdo->resetCounts();
        self::assertSame(0, $pdo->queries);
        self::assertSame(0, $pdo->fetchedJsonBytes);
    }
}
