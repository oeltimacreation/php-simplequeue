<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Support;

use Oeltima\SimpleQueue\Contract\ClockInterface;
use Oeltima\SimpleQueue\Storage\PdoJobStorage;
use Oeltima\SimpleQueue\Tests\DbHelper;
use PDO;

final class SqliteFixture
{
    /**
     * Create an exception-mode in-memory SQLite connection.
     *
     * @return PDO SQLite connection
     */
    public static function memoryPdo(): PDO
    {
        return self::configure(new PDO('sqlite::memory:'));
    }

    /**
     * Create an exception-mode file-backed SQLite connection.
     *
     * @param string $path SQLite database path
     * @return PDO SQLite connection
     */
    public static function filePdo(string $path): PDO
    {
        return self::configure(new PDO('sqlite:' . $path));
    }

    /**
     * Create a schema-backed in-memory SQLite storage.
     *
     * @param string $table Table name
     * @param ClockInterface|null $clock Optional test clock
     * @return PdoJobStorage SQLite storage
     */
    public static function createStorage(string $table = 'background_jobs', ?ClockInterface $clock = null): PdoJobStorage
    {
        $pdo = self::memoryPdo();
        DbHelper::createSchema($pdo, $table);

        return new PdoJobStorage($pdo, $table, $clock);
    }

    private static function configure(PDO $pdo): PDO
    {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }
}
