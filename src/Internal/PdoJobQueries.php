<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Internal;

use Oeltima\SimpleQueue\Contract\JobSummary;
use Oeltima\SimpleQueue\Contract\JobStatus;
use PDO;
use PDOStatement;

/** @internal @mixin \Oeltima\SimpleQueue\Storage\PdoJobStorage */
trait PdoJobQueries
{
    private const SUMMARY_COLUMNS = 'id, queue, type, status, attempts, max_attempts, progress, '
        . 'progress_message, created_at, updated_at';

    /** @inheritDoc */
    public function listSummaries(
        ?JobStatus $status = null,
        ?string $queue = null,
        ?int $beforeId = null,
        int $limit = 100
    ): array {
        JobQueryRules::validate($queue, $beforeId, $limit);
        [$sql, $params] = $this->filteredQuery('SELECT ' . self::SUMMARY_COLUMNS, new JobFilter($status, $queue));
        if ($beforeId !== null) {
            $sql .= ' AND id < :before_id';
            $params['before_id'] = $beforeId;
        }
        $sql .= ' ORDER BY id DESC LIMIT :limit';
        return $this->withReconnect(function (PDO $pdo) use ($sql, $params, $limit): array {
            return $this->fetchSummaries($this->boundedStatement($pdo, $sql, $params, $limit));
        });
    }

    /** @inheritDoc */
    public function findSummaries(array $jobIds): array
    {
        $ids = JobQueryRules::identities($jobIds);
        if ($ids === []) {
            return [];
        }
        return $this->withReconnect(function (PDO $pdo) use ($ids): array {
            $found = [];
            foreach (array_chunk($ids, 500) as $chunk) {
                $placeholders = implode(', ', array_fill(0, count($chunk), '?'));
                $statement = $this->prepare($pdo, 'SELECT ' . self::SUMMARY_COLUMNS .
                    " FROM {$this->table} WHERE id IN ({$placeholders})");
                $statement->execute($chunk);
                foreach ($this->fetchSummaries($statement) as $summary) {
                    $found[$summary->id] = $summary;
                }
            }
            $ordered = [];
            foreach ($ids as $id) {
                if (isset($found[$id])) {
                    $ordered[$id] = $found[$id];
                }
            }
            return $ordered;
        });
    }

    /** @inheritDoc */
    public function countByStatus(?string $queue = null): array
    {
        JobQueryRules::validate($queue, null, 1);
        [$sql, $params] = $this->filteredQuery('SELECT status, COUNT(*) AS total', new JobFilter(null, $queue));
        return $this->withReconnect(function (PDO $pdo) use ($sql, $params): array {
            $statement = $this->prepare($pdo, $sql . ' GROUP BY status');
            $statement->execute($params);
            $counts = JobQueryRules::emptyCounts();
            while (is_array($row = $statement->fetch(PDO::FETCH_ASSOC))) {
                $status = $row['status'] ?? null;
                $total = $row['total'] ?? null;
                if (!is_string($status) || !(is_int($total) || (is_string($total) && ctype_digit($total)))) {
                    throw new \UnexpectedValueException('Invalid grouped status count');
                }
                $counts[JobStatus::from($status)->value] = (int) $total;
            }
            $statement->closeCursor();
            return $counts;
        });
    }

    /** @return list<JobSummary> Compact rows */
    private function fetchSummaries(PDOStatement $statement): array
    {
        $summaries = [];
        while (is_array($row = $statement->fetch(PDO::FETCH_ASSOC))) {
            $summaries[] = JobSummary::fromRaw($row);
        }
        $statement->closeCursor();
        return $summaries;
    }
}
