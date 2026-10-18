<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Contract;

use PDO;

/** Optional transactional application integration; never perform network I/O here. */
interface PdoJobParticipantInterface
{
    /**
     * Validate/register references before inserting any jobs in this transaction.
     *
     * @param PDO $pdo Transaction-scoped connection; must not commit or reconnect
     * @param list<array<string, mixed>> $definitions Original creation definitions
     */
    public function beforeCreate(PDO $pdo, array $definitions): void;

    /**
     * Write transactional side effects after an actual winning mutation.
     *
     * @param PDO $pdo Same transaction-scoped connection
     * @param StorageTransition $transition Successful mutation identity
     */
    public function afterTransition(PDO $pdo, StorageTransition $transition): void;
}
