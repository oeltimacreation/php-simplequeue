<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Internal;

use PDO;

/**
 * Owns database-specific transaction commands for a single claim attempt.
 *
 * @internal
 */
final class PdoClaimTransaction
{
    private bool $active = false;

    /**
     * @param PDO $pdo Claim connection
     * @param string $driver PDO driver name
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $driver
    ) {
    }

    /**
     * Begin the claim transaction with the driver's required locking mode.
     */
    public function begin(): void
    {
        if ($this->driver === 'sqlite') {
            $this->pdo->exec('BEGIN IMMEDIATE');
        } else {
            $this->pdo->beginTransaction();
        }
        $this->active = true;
    }

    /**
     * Commit the active claim transaction.
     */
    public function commit(): void
    {
        if ($this->driver === 'sqlite') {
            $this->pdo->exec('COMMIT');
        } else {
            $this->pdo->commit();
        }
        $this->active = false;
    }

    /**
     * Roll back without replacing the exception that caused claim failure.
     */
    public function rollbackIgnoringFailure(): void
    {
        if (!$this->active) {
            return;
        }

        try {
            if ($this->driver === 'sqlite') {
                $this->pdo->exec('ROLLBACK');
            } elseif ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
        } catch (\Throwable) {
            // Preserve the original claim exception.
        } finally {
            $this->active = false;
        }
    }
}
