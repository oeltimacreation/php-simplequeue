<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Tests\Support;

use Oeltima\SimpleQueue\Contract\PdoJobParticipantInterface;
use Oeltima\SimpleQueue\Contract\StorageTransition;
use PDO;

final class TransactionalOutbox implements PdoJobParticipantInterface
{
    public bool $fail = false;

    /** @inheritDoc */
    public function beforeCreate(PDO $pdo, array $definitions): void
    {
        foreach ($definitions as $definition) {
            $statement = $pdo->prepare('INSERT INTO input_registrations (type) VALUES (?)');
            $statement->execute([$definition['type']]);
        }
    }

    /** @inheritDoc */
    public function afterTransition(PDO $pdo, StorageTransition $transition): void
    {
        $statement = $pdo->prepare('INSERT INTO job_outbox (job_id, operation, status, attempts) SELECT id, ?, status, attempts FROM background_jobs WHERE id = ?');
        $statement->execute([$transition->operation, $transition->jobId]);
        if ($this->fail) {
            throw new \RuntimeException('Outbox rejected transition');
        }
    }
}
