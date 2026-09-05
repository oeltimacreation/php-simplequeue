<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Internal;

use PDO;
use PDOStatement;

/**
 * Executes validated multi-row inserts and returns their exact identifiers.
 *
 * @internal
 * @phpstan-import-type ValidatedJobShape from JobStorageRules
 * @phpstan-type InsertContext array{pdo: PDO, driver: string, now: string}
 */
final readonly class PdoBatchJobInserter
{
    private string $table;

    /** @param array{table: string} $configuration */
    public function __construct(array $configuration)
    {
        $this->table = $configuration['table'];
    }

    /**
     * @param InsertContext $context
     * @param list<ValidatedJobShape> $jobs
     * @return list<int>
     */
    public function insert(array $context, array $jobs): array
    {
        if ($this->requiresSqliteSubchunks($context, $jobs)) {
            return $this->insertSqliteSubchunks($context, $jobs);
        }
        if ($this->supportsReturning($context)) {
            return $this->insertReturning($context, $jobs);
        }
        if ($context['driver'] === 'sqlite') {
            return $this->insertSqliteRows($context, $jobs);
        }
        return $this->insertWithDerivedIds($context, $jobs);
    }

    /**
     * @param InsertContext $context
     * @param list<ValidatedJobShape> $jobs
     */
    private function requiresSqliteSubchunks(array $context, array $jobs): bool
    {
        if ($context['driver'] !== 'sqlite') {
            return false;
        }
        return count($jobs) > 100;
    }

    /**
     * @param InsertContext $context
     * @param list<ValidatedJobShape> $jobs
     * @return list<int>
     */
    private function insertSqliteSubchunks(array $context, array $jobs): array
    {
        $ids = [];
        foreach (array_chunk($jobs, 100) as $chunk) {
            array_push($ids, ...$this->insert($context, $chunk));
        }
        return $ids;
    }

    /** @param InsertContext $context */
    private function supportsReturning(array $context): bool
    {
        if ($context['driver'] === 'pgsql') {
            return true;
        }
        if ($context['driver'] !== 'sqlite') {
            return false;
        }
        return $this->sqliteSupportsReturning($context['pdo']);
    }

    /**
     * @param list<ValidatedJobShape> $jobs
     * @return array{list<string>, list<mixed>}
     */
    private function values(array $jobs, string $now): array
    {
        $placeholders = [];
        $params = [];
        foreach ($jobs as $job) {
            $placeholders[] = "(?, ?, 'pending', ?, 0, ?, ?, ?, ?, ?)";
            array_push(
                $params,
                $job['queue'],
                $job['type'],
                $job['encodedPayload'],
                $job['maxAttempts'],
                $job['availableAt'],
                $job['requestId'],
                $now,
                $now
            );
        }
        return [$placeholders, $params];
    }

    /**
     * @param InsertContext $context
     * @param list<ValidatedJobShape> $jobs
     * @return list<int>
     */
    private function insertReturning(array $context, array $jobs): array
    {
        [$placeholders, $params] = $this->values($jobs, $context['now']);
        $sql = $this->insertPrefix() . implode(', ', $placeholders) . ' RETURNING id';
        $stmt = $this->prepare($context['pdo'], $sql);
        $stmt->execute($params);
        /** @var list<string|int> $raw */
        $raw = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
        return array_map(static fn ($id): int => (int) $id, $raw);
    }

    /**
     * @param InsertContext $context
     * @param list<ValidatedJobShape> $jobs
     * @return list<int>
     */
    private function insertSqliteRows(array $context, array $jobs): array
    {
        $sql = $this->insertPrefix() . "(?, ?, 'pending', ?, 0, ?, ?, ?, ?, ?)";
        $ids = [];
        foreach ($jobs as $job) {
            [, $params] = $this->values([$job], $context['now']);
            $stmt = $this->prepare($context['pdo'], $sql);
            $stmt->execute($params);
            $ids[] = (int) $context['pdo']->lastInsertId();
        }
        return $ids;
    }

    /**
     * @param InsertContext $context
     * @param list<ValidatedJobShape> $jobs
     * @return list<int>
     */
    private function insertWithDerivedIds(array $context, array $jobs): array
    {
        [$placeholders, $params] = $this->values($jobs, $context['now']);
        $stmt = $this->prepare($context['pdo'], $this->insertPrefix() . implode(', ', $placeholders));
        $stmt->execute($params);
        $count = $stmt->rowCount();
        if ($count === 0) {
            return [];
        }
        return $this->derivedIds($context, ['first' => (int) $context['pdo']->lastInsertId(), 'count' => $count]);
    }

    /**
     * @param InsertContext $context
     * @param array{first: int, count: int} $range
     * @return list<int>
     */
    private function derivedIds(array $context, array $range): array
    {
        $increment = $this->mysqlAutoIncrement($context);
        $ids = [];
        for ($index = 0; $index < $range['count']; $index++) {
            $ids[] = $range['first'] + ($index * $increment);
        }
        $this->validateInsertedIds($context['pdo'], $ids);
        return $ids;
    }

    private function insertPrefix(): string
    {
        $columns = [
            'queue', 'type', 'status', 'payload', 'attempts', 'max_attempts',
            'available_at', 'request_id', 'created_at', 'updated_at',
        ];
        return "INSERT INTO {$this->table} (" . implode(', ', $columns) . ') VALUES ';
    }

    private function sqliteSupportsReturning(PDO $pdo): bool
    {
        try {
            $version = $pdo->query('select sqlite_version()');
            if (!$version instanceof PDOStatement) {
                return false;
            }
            $raw = $version->fetchColumn(0);
            if (!is_string($raw)) {
                return false;
            }
            return version_compare($raw, '3.35.0', '>=');
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param InsertContext $context */
    private function mysqlAutoIncrement(array $context): int
    {
        if ($context['driver'] !== 'mysql') {
            return 1;
        }
        try {
            $stmt = $context['pdo']->query('SELECT @@SESSION.auto_increment_increment');
            if (!$stmt instanceof PDOStatement) {
                return 1;
            }
            return $this->positiveIncrement($stmt->fetchColumn(0));
        } catch (\Throwable) {
            return 1;
        }
    }

    private function positiveIncrement(mixed $value): int
    {
        if (!is_numeric($value)) {
            return 1;
        }
        $increment = (int) $value;
        return $increment >= 1 ? $increment : 1;
    }

    /** @param list<int> $ids */
    private function validateInsertedIds(PDO $pdo, array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->prepare($pdo, "SELECT COUNT(*) FROM {$this->table} WHERE id IN ({$placeholders})");
        $stmt->execute($ids);
        if ((int) $stmt->fetchColumn(0) !== count($ids)) {
            throw new \RuntimeException('Batch insert ID derivation mismatch');
        }
    }

    private function prepare(PDO $pdo, string $sql): PDOStatement
    {
        $stmt = $pdo->prepare($sql);
        if (!$stmt instanceof PDOStatement) {
            throw new \RuntimeException('Failed to prepare SQL statement');
        }
        return $stmt;
    }
}
