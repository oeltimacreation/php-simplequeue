<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Internal;

use Oeltima\SimpleQueue\Contract\JobStatus;

/**
 * Performs PDO stale-job transitions without owning connection policy.
 *
 * @internal
 * @phpstan-type MutationRequest array{sql: string, params: array<string, mixed>, operation: string}
 * @phpstan-type RowRequest array{sql: string, params: array<string, mixed>, limit: int}
 */
final readonly class PdoStaleJobRecovery
{
    private const UPDATE_CHUNK_SIZE = 100;

    private string $table;
    /** @var \Closure(): string */
    private \Closure $now;
    /** @var \Closure(int): string */
    private \Closure $threshold;
    /** @var \Closure(MutationRequest): \PDOStatement */
    private \Closure $mutate;
    /** @var \Closure(RowRequest): list<array<string, mixed>> */
    private \Closure $rows;
    private bool $recoverRowsIndividually;

    /**
     * @param array{
     *     table: string,
     *     now: \Closure(): string,
     *     threshold: \Closure(int): string,
     *     mutate: \Closure(MutationRequest): \PDOStatement,
     *     rows: \Closure(RowRequest): list<array<string, mixed>>,
     *     recoverRowsIndividually: bool
     * } $dependencies
     */
    public function __construct(array $dependencies)
    {
        $this->table = $dependencies['table'];
        $this->now = $dependencies['now'];
        $this->threshold = $dependencies['threshold'];
        $this->mutate = $dependencies['mutate'];
        $this->rows = $dependencies['rows'];
        $this->recoverRowsIndividually = $dependencies['recoverRowsIndividually'];
    }

    public function recoverAll(int $ttlSeconds): int
    {
        JobStorageRules::validateStaleRecovery($ttlSeconds, 1);
        $context = $this->recoveryContext($ttlSeconds);
        $failed = $this->recoverUnscoped($context, JobStatus::Failed);
        $pending = $this->recoverUnscoped($context, JobStatus::Pending);
        return $failed + $pending;
    }

    /** Recover every stale job through bounded individual transitions for participants. */
    public function recoverIndividually(int $ttlSeconds): int
    {
        JobStorageRules::validateStaleRecovery($ttlSeconds, 1);
        $context = $this->recoveryContext($ttlSeconds);
        $cursor = 0;
        $total = 0;
        do {
            $rows = ($this->rows)([
                'sql' => "SELECT id FROM {$this->table} WHERE status = 'running'
                    AND attempts >= 0 AND max_attempts >= 1 AND id > :after_id
                    AND (locked_at IS NULL OR locked_at < :threshold) ORDER BY id LIMIT :limit",
                'params' => ['after_id' => $cursor, 'threshold' => $context['threshold']],
                'limit' => 100,
            ]);
            $total += $this->recoverRows($rows, $context);
            foreach ($rows as $row) {
                $cursor = max($cursor, $this->staleRowId($row) ?? 0);
            }
        } while (count($rows) === 100);
        return $total;
    }

    /**
     * @param array{queue: string, ttlSeconds: int, limit: int} $scope Recovery scope
     * @return int Number of rows transitioned
     */
    public function recoverQueue(array $scope): int
    {
        JobStorageRules::validateQueueOrType($scope['queue'], 'Queue');
        JobStorageRules::validateStaleRecovery($scope['ttlSeconds'], $scope['limit']);
        $context = $this->recoveryContext($scope['ttlSeconds']);
        $rows = ($this->rows)([
            'sql' => $this->staleRowsSql(),
            'params' => ['queue' => $scope['queue'], 'threshold' => $context['threshold']],
            'limit' => $scope['limit'],
        ]);
        return $this->recoverRowsIndividually
            ? $this->recoverRows($rows, $context)
            : $this->recoverChunks($rows, $this->guardedParams($context) + ['queue' => $scope['queue']]);
    }

    /** @return array{now: string, threshold: string, error: string} */
    private function recoveryContext(int $ttlSeconds): array
    {
        return [
            'now' => ($this->now)(),
            'threshold' => ($this->threshold)($ttlSeconds),
            'error' => 'Job timed out / worker crashed (stale recovery)',
        ];
    }

    /** @param array{now: string, threshold: string, error: string} $context */
    private function recoverUnscoped(array $context, JobStatus $outcome): int
    {
        $terminal = $outcome === JobStatus::Failed;
        $terminalAssignments = $terminal
            ? 'completed_at = :completed_at,'
            : 'result = NULL, completed_at = NULL, progress = NULL, progress_message = NULL,';
        $attemptComparison = $terminal ? '>=' : '<';
        $sql = "UPDATE {$this->table}
            SET status = '{$outcome->value}', attempts = attempts + 1,
                error_message = :error_message, {$terminalAssignments}
                available_at = :available_at, locked_by = NULL, locked_at = NULL,
                lease_token = NULL, updated_at = :updated_at
            WHERE status = 'running'
            AND (locked_at IS NULL OR locked_at < :stale_threshold)
            AND attempts + 1 {$attemptComparison} max_attempts";

        $params = [
            'stale_threshold' => $context['threshold'],
            'error_message' => $context['error'],
            'available_at' => $context['now'],
            'updated_at' => $context['now'],
        ];
        if ($terminal) {
            $params['completed_at'] = $context['now'];
        }
        return ($this->mutate)([
            'sql' => $sql,
            'params' => $params,
            'operation' => 'recoverStaleJobs',
        ])->rowCount();
    }

    private function staleRowsSql(): string
    {
        return "SELECT id FROM {$this->table} WHERE queue = :queue AND status = 'running' " .
            'AND attempts >= 0 AND max_attempts >= 1 ' .
            'AND (locked_at IS NULL OR locked_at < :threshold) ' .
            'ORDER BY CASE WHEN locked_at IS NULL THEN 0 ELSE 1 END, locked_at ASC, id ASC LIMIT :limit';
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array{now: string, threshold: string, error: string} $context
     */
    private function recoverRows(array $rows, array $context): int
    {
        $recovered = 0;
        foreach ($rows as $row) {
            $id = $this->staleRowId($row);
            if ($id === null) {
                continue;
            }
            $changed = ($this->mutate)([
                'sql' => $this->recoverGuardedSql('id = :id'),
                'params' => $this->guardedParams($context) + ['id' => $id],
                'operation' => 'recoverStaleJobsForQueue',
            ]);
            $recovered += $changed->rowCount();
        }
        return $recovered;
    }

    /**
     * @param list<array<string, mixed>> $rows Selected recovery identities
     * @param array<string, string> $params Bound scope and transition values
     */
    private function recoverChunks(array $rows, array $params): int
    {
        $ids = array_filter(array_map($this->staleRowId(...), $rows), static fn (?int $id): bool => $id !== null);
        $recovered = 0;
        foreach (array_chunk($ids, self::UPDATE_CHUNK_SIZE) as $chunk) {
            $bindings = [];
            foreach ($chunk as $index => $id) {
                $bindings['job_' . $index] = $id;
            }
            $idsSql = ':' . implode(', :', array_keys($bindings));
            $changed = ($this->mutate)([
                'sql' => $this->recoverGuardedSql("id IN ({$idsSql}) AND queue = :queue"),
                'params' => $params + $bindings,
                'operation' => 'recoverStaleJobsForQueue',
            ]);
            $recovered += $changed->rowCount();
        }
        return $recovered;
    }

    /**
     * @param array{now: string, threshold: string, error: string} $context
     * @return array<string, string>
     */
    private function guardedParams(array $context): array
    {
        return [
            'threshold' => $context['threshold'],
            'error_message' => $context['error'],
            'completed_at' => $context['now'],
            'available_at' => $context['now'],
            'updated_at' => $context['now'],
        ];
    }

    /** @param array<string, mixed> $row Lean recovery identity */
    private function staleRowId(array $row): ?int
    {
        $id = $row['id'] ?? null;
        if (is_int($id)) {
            return $id;
        }
        if (is_string($id) && ctype_digit($id)) {
            return (int) $id;
        }
        return null;
    }

    private function recoverGuardedSql(string $predicate): string
    {
        // Evaluate terminality before incrementing attempts (MySQL assignments run left to right).
        $terminal = 'attempts + 1 >= max_attempts';
        return "UPDATE {$this->table} SET
            status = CASE WHEN {$terminal} THEN 'failed' ELSE 'pending' END,
            completed_at = CASE WHEN {$terminal} THEN COALESCE(:completed_at, completed_at) ELSE NULL END,
            result = CASE WHEN {$terminal} THEN result ELSE NULL END,
            progress = CASE WHEN {$terminal} THEN progress ELSE NULL END,
            progress_message = CASE WHEN {$terminal} THEN progress_message ELSE NULL END,
            error_message = :error_message, available_at = :available_at,
            locked_by = NULL, locked_at = NULL, lease_token = NULL, updated_at = :updated_at,
            attempts = attempts + 1
            WHERE {$predicate} AND status = 'running' AND attempts >= 0 AND max_attempts >= 1
            AND (locked_at IS NULL OR locked_at < :threshold)";
    }
}
