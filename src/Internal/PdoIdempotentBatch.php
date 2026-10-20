<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Internal;

use Oeltima\SimpleQueue\Contract\IdempotentJobResult;
use PDO;
use PDOException;

/**
 * @internal
 * @phpstan-import-type JobDefinitionShape from \Oeltima\SimpleQueue\Contract\JobStorageInterface
 */
final readonly class PdoIdempotentBatch
{
    public function __construct(private string $table)
    {
    }

    /**
     * @param PDO $pdo Mutation connection
     * @param list<JobDefinitionShape> $jobs Validated definitions
     * @param callable(list<JobDefinitionShape>): list<int> $insert Atomic bulk insert
     * @return list<IdempotentJobResult> Ordered results
     */
    public function create(PDO $pdo, array $jobs, callable $insert): array
    {
        $callerOwned = $pdo->inTransaction();
        try {
            return $this->atomic($pdo, fn (): array => $this->bulk($pdo, $jobs, $insert));
        } catch (PDOException $exception) {
            $unique = in_array((string) $exception->getCode(), ['23000', '23505'], true);
            $aborted = !$callerOwned && in_array((string) $exception->getCode(), ['40001', '40P01'], true);
            if (!$unique && !$aborted) {
                throw $exception;
            }
            // A concurrent winner or database-collation alias invalidated the bulk snapshot.
            // Roll back the whole attempted batch, then resolve each key in one new unit.
            return $this->atomic($pdo, fn (): array => $this->sequential($pdo, $jobs, $insert));
        }
    }

    /**
     * @param PDO $pdo Connection
     * @param list<JobDefinitionShape> $jobs Definitions
     * @param callable(list<JobDefinitionShape>): list<int> $insert Savepoint-protected insert
     * @return list<IdempotentJobResult> Race/collation fallback outcomes
     */
    private function sequential(PDO $pdo, array $jobs, callable $insert): array
    {
        $results = [];
        foreach ($jobs as $job) {
            $existing = $this->existing($pdo, [$job]);
            $key = IdempotentBatch::key($job);
            if (isset($existing[$key])) {
                $results[] = new IdempotentJobResult($existing[$key], false);
                continue;
            }
            try {
                $ids = $insert([$job]);
                $results[] = new IdempotentJobResult($ids[0], true);
            } catch (PDOException $exception) {
                if (!in_array((string) $exception->getCode(), ['23000', '23505'], true)) {
                    throw $exception;
                }
                $existing = $this->existing($pdo, [$job]);
                if (!isset($existing[$key])) {
                    throw $exception;
                }
                $results[] = new IdempotentJobResult($existing[$key], false);
            }
        }
        return $results;
    }

    /**
     * @param PDO $pdo Connection
     * @param list<JobDefinitionShape> $jobs Definitions
     * @param callable(list<JobDefinitionShape>): list<int> $insert Bulk creation
     * @return list<IdempotentJobResult> Outcomes
     */
    private function bulk(PDO $pdo, array $jobs, callable $insert): array
    {
        $unique = [];
        foreach ($jobs as $job) {
            $unique[IdempotentBatch::key($job)] ??= $job;
        }
        $lookup = $unique;
        ksort($lookup, SORT_STRING);
        $ids = $this->existing($pdo, array_values($lookup));
        $missing = array_diff_key($unique, $ids);
        $newIds = $insert(array_values($missing));
        foreach (array_keys($missing) as $index => $key) {
            $ids[$key] = $newIds[$index];
        }
        return IdempotentBatch::outcomes($jobs, $ids, array_fill_keys($newIds, true));
    }

    /**
     * @param PDO $pdo Connection
     * @param list<JobDefinitionShape> $jobs Unique requests
     * @return array<string, int> Requested spelling to winning ID, honoring database collation
     */
    private function existing(PDO $pdo, array $jobs): array
    {
        $ids = [];
        foreach (array_chunk($jobs, 100) as $chunk) {
            $statement = $this->lookupStatement($pdo, $chunk);
            $ids += $this->readExistingIds($statement);
        }
        return $ids;
    }

    /**
     * @param PDO $pdo Connection
     * @param list<JobDefinitionShape> $jobs One bounded request chunk
     * @return \PDOStatement Executed locking lookup
     */
    private function lookupStatement(PDO $pdo, array $jobs): \PDOStatement
    {
        $locking = match ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) {
            'pgsql' => ' FOR UPDATE OF jobs',
            'mysql' => ' FOR UPDATE',
            default => '',
        };
        $requests = implode(' UNION ALL ', array_fill(0, count($jobs), 'SELECT ? AS request_id'));
        $statement = $pdo->prepare("SELECT requested.request_id AS requested_id, jobs.id
            FROM ({$requests}) requested JOIN {$this->table} jobs ON jobs.request_id = requested.request_id
            WHERE jobs.status IN ('pending', 'running')" . $locking);
        if ($statement === false) {
            throw new \RuntimeException('Unable to prepare idempotent batch lookup');
        }
        $statement->execute(array_column($jobs, 'requestId'));
        return $statement;
    }

    /**
     * @param \PDOStatement $statement Executed lookup
     * @return array<string, int> Requested spellings and winning IDs
     */
    private function readExistingIds(\PDOStatement $statement): array
    {
        $ids = [];
        while (is_array($row = $statement->fetch(PDO::FETCH_ASSOC))) {
            $requestId = $row['requested_id'] ?? null;
            if (!is_string($requestId)) {
                throw new \RuntimeException('Invalid idempotent batch lookup result');
            }
            $ids['request:' . $requestId] = $this->lookupId($row['id'] ?? null);
        }
        $statement->closeCursor();
        return $ids;
    }

    private function lookupId(mixed $id): int
    {
        if (is_string($id) && ctype_digit($id)) {
            $id = (int) $id;
        }
        if (!is_int($id)) {
            throw new \RuntimeException('Invalid idempotent batch lookup result');
        }
        return JobStorageRules::validatePositiveId($id);
    }

    /**
     * @param PDO $pdo Connection
     * @param callable(): list<IdempotentJobResult> $operation Atomic operation
     * @return list<IdempotentJobResult> Outcomes
     */
    private function atomic(PDO $pdo, callable $operation): array
    {
        $owns = $this->beginBatchTransaction($pdo);
        try {
            $result = $operation();
            $this->commitBatchTransaction($pdo, $owns);
            return $result;
        } catch (\Throwable $exception) {
            $this->rollbackBatchTransaction($pdo, $owns);
            throw $exception;
        }
    }

    private function beginBatchTransaction(PDO $pdo): bool
    {
        $owns = !$pdo->inTransaction();
        if ($owns) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT simplequeue_idempotent_batch');
        }
        return $owns;
    }

    private function commitBatchTransaction(PDO $pdo, bool $owns): void
    {
        if ($owns) {
            $pdo->commit();
        } else {
            $pdo->exec('RELEASE SAVEPOINT simplequeue_idempotent_batch');
        }
    }

    private function rollbackBatchTransaction(PDO $pdo, bool $owns): void
    {
        try {
            if ($owns) {
                $pdo->rollBack();
            } else {
                $pdo->exec('ROLLBACK TO SAVEPOINT simplequeue_idempotent_batch');
                $pdo->exec('RELEASE SAVEPOINT simplequeue_idempotent_batch');
            }
        } catch (\Throwable) {
            // Preserve the original error; storage classifies uncertain mutations.
        }
    }
}
