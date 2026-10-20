<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Support;

use PDO;
use PHPUnit\Framework\TestCase;

final class DatabaseFixture
{
    /**
     * @param string $service MYSQL or POSTGRES
     * @return PDO Required/configured service connection
     */
    public static function connect(string $service): PDO
    {
        $dsn = getenv($service . '_DSN');
        $driver = $service === 'MYSQL' ? 'mysql' : 'pgsql';
        if (!is_string($dsn) || $dsn === '') {
            self::unavailable($service);
        }
        if (!in_array($driver, PDO::getAvailableDrivers(), true)) {
            self::unavailable($service);
        }
        $user = getenv($service . '_USER');
        $password = getenv($service . '_PASSWORD');
        return new PDO($dsn, $user === false ? '' : $user, $password === false ? '' : $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }

    private static function unavailable(string $service): never
    {
        if (getenv('SIMPLEQUEUE_DATABASE_SERVICE') === strtolower($service)) {
            TestCase::fail('Configured database lane requires its DSN and PDO driver');
        }
        TestCase::markTestSkipped('Optional ' . $service . ' service is not configured');
    }
}
