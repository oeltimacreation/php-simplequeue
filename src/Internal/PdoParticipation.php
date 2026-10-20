<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Internal;

use Oeltima\SimpleQueue\Contract\PdoJobParticipantInterface;
use Oeltima\SimpleQueue\Contract\StorageTransition;
use PDO;
use PDOStatement;

/** @internal Executes opt-in application writes without changing default storage costs. */
final class PdoParticipation
{
    private int $depth = 0;

    public function __construct(private readonly PdoJobParticipantInterface $participant)
    {
    }

    /**
     * @template T of int|list<int>
     * @param PDO $pdo Connection
     * @param list<array<string, mixed>> $definitions Creation input
     * @param callable(): T $insert Insertion
     * @return T Inserted identities
     */
    public function create(PDO $pdo, array $definitions, callable $insert): int|array
    {
        return $this->transaction($pdo, function () use ($pdo, $definitions, $insert): int|array {
            $this->participant->beforeCreate($pdo, $definitions);
            $ids = $insert();
            foreach (is_int($ids) ? [$ids] : $ids as $id) {
                $this->participant->afterTransition($pdo, new StorageTransition($id, 'created'));
            }
            return $ids;
        });
    }

    /**
     * @param PDO $pdo Connection
     * @param string $sql Guarded mutation
     * @param array<string, mixed> $params Bound values
     * @param string $operation Operation name
     * @return PDOStatement Executed statement
     */
    public function execute(PDO $pdo, string $sql, array $params, string $operation): PDOStatement
    {
        return $this->transaction($pdo, function () use ($pdo, $sql, $params, $operation): PDOStatement {
            $statement = $pdo->prepare($sql);
            if (!$statement instanceof PDOStatement) {
                throw new \RuntimeException('Unable to prepare participant mutation');
            }
            $statement->execute($params);
            $id = $params['id'] ?? null;
            if ($statement->rowCount() > 0 && is_int($id)) {
                $this->participant->afterTransition($pdo, new StorageTransition($id, $operation));
            }
            return $statement;
        });
    }

    /**
     * @template T
     * @param PDO $pdo Connection
     * @param callable(): T $operation Transactional unit
     * @return T Result
     */
    private function transaction(PDO $pdo, callable $operation): mixed
    {
        $owns = !$pdo->inTransaction();
        $savepoint = 'simplequeue_participant_' . ++$this->depth;
        if ($owns) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT ' . $savepoint);
        }
        try {
            $result = $operation();
            if ($owns) {
                $pdo->commit();
            } else {
                $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }
            return $result;
        } catch (\Throwable $exception) {
            try {
                if ($owns) {
                    $pdo->rollBack();
                } else {
                    $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                    $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
                }
            } catch (\Throwable) {
                // Preserve the mutation/commit failure for storage outcome classification.
            }
            throw $exception;
        } finally {
            $this->depth--;
        }
    }
}
