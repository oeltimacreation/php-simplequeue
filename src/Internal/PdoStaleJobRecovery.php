<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Internal;

/**
 * Performs PDO stale-job transitions without owning connection policy.
 *
 * @internal
 * @phpstan-type MutationRequest array{sql: string, params: array<string, mixed>, operation: string}
 * @phpstan-type RowRequest array{sql: string, params: array<string, mixed>, limit: int}
 */
final readonly class PdoStaleJobRecovery
{
    private string $table;
    /** @var \Closure(): string */
    private \Closure $now;
    /** @var \Closure(int): string */
    private \Closure $threshold;
    /** @var \Closure(MutationRequest): \PDOStatement */
    private \Closure $mutate;
    /** @var \Closure(RowRequest): list<array<string, mixed>> */
    private \Closure $rows;

    /**
     * @param array{
     *     table: string,
     *     now: \Closure(): string,
     *     threshold: \Closure(int): string,
     *     mutate: \Closure(MutationRequest): \PDOStatement,
     *     rows: \Closure(RowRequest): list<array<string, mixed>>
     * } $dependencies
     */
    public function __construct(array $dependencies)
    {
        $this->table = $dependencies['table'];
        $this->now = $dependencies['now'];
        $this->threshold = $dependencies['threshold'];
        $this->mutate = $dependencies['mutate'];
        $this->rows = $dependencies['rows'];
    }

    public function recoverAll(int $ttlSeconds): int
    {
        JobStorageRules::validateStaleRecovery($ttlSeconds, 1);
        $context = $this->recoveryContext($ttlSeconds);
        $failed = ($this->mutate)([
            'sql' => $this->recoverAllSql(true),
            'params' => $this->recoverAllParams($context, true),
            'operation' => 'recoverStaleJobs',
        ]);
        $pending = ($this->mutate)([
            'sql' => $this->recoverAllSql(false),
            'params' => $this->recoverAllParams($context, false),
            'operation' => 'recoverStaleJobs',
        ]);
        return $failed->rowCount() + $pending->rowCount();
    }

    public function recoverQueue(string $queue, int $ttlSeconds, int $limit): int
    {
        JobStorageRules::validateQueueOrType($queue, 'Queue');
        JobStorageRules::validateStaleRecovery($ttlSeconds, $limit);
        $context = $this->recoveryContext($ttlSeconds);
        $rows = ($this->rows)([
            'sql' => $this->staleRowsSql(),
            'params' => ['queue' => $queue, 'threshold' => $context['threshold']],
            'limit' => $limit,
        ]);
        return $this->recoverRows($rows, $context);
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

    private function recoverAllSql(bool $terminal): string
    {
        $terminalAssignments = $terminal
            ? 'completed_at = :completed_at,'
            : 'result = NULL, completed_at = NULL, progress = NULL, progress_message = NULL,';
        $attemptComparison = $terminal ? '>=' : '<';
        $status = $terminal ? 'failed' : 'pending';
        return "UPDATE {$this->table}
            SET status = '{$status}', attempts = attempts + 1,
                error_message = :error_message, {$terminalAssignments}
                available_at = :available_at, locked_by = NULL, locked_at = NULL,
                lease_token = NULL, updated_at = :updated_at
            WHERE status = 'running'
            AND (locked_at IS NULL OR locked_at < :stale_threshold)
            AND attempts + 1 {$attemptComparison} max_attempts";
    }

    /**
     * @param array{now: string, threshold: string, error: string} $context
     * @return array<string, mixed>
     */
    private function recoverAllParams(array $context, bool $terminal): array
    {
        $params = [
            'stale_threshold' => $context['threshold'],
            'error_message' => $context['error'],
            'available_at' => $context['now'],
            'updated_at' => $context['now'],
        ];
        if ($terminal) {
            $params['completed_at'] = $context['now'];
        }
        return $params;
    }

    private function staleRowsSql(): string
    {
        return "SELECT * FROM {$this->table} WHERE queue = :queue AND status = 'running' " .
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
            $recovered += $this->recoverRow(JobDataHydrator::hydrateStrict($row), $context);
        }
        return $recovered;
    }

    /** @param array{now: string, threshold: string, error: string} $context */
    private function recoverRow(\Oeltima\SimpleQueue\Contract\JobData $job, array $context): int
    {
        $terminal = !RetryDecision::forAttempt($job->attempts + 1, $job->maxAttempts)->shouldRetry();
        $changed = ($this->mutate)([
            'sql' => $this->recoverRowSql($terminal),
            'params' => $this->recoverRowParams($job, $context, $terminal),
            'operation' => 'recoverStaleJobsForQueue',
        ]);
        return $changed->rowCount();
    }

    private function recoverRowSql(bool $terminal): string
    {
        $reset = $terminal
            ? 'completed_at = :completed_at,'
            : 'result = NULL, completed_at = NULL, progress = NULL, progress_message = NULL,';
        return "UPDATE {$this->table} SET status = :status, attempts = :attempts,
            error_message = :error_message, {$reset} available_at = :available_at,
            locked_by = NULL, locked_at = NULL, lease_token = NULL, updated_at = :updated_at
            WHERE id = :id AND status = 'running'
            AND (locked_at IS NULL OR locked_at < :threshold)";
    }

    /**
     * @param array{now: string, threshold: string, error: string} $context
     * @return array<string, mixed>
     */
    private function recoverRowParams(
        \Oeltima\SimpleQueue\Contract\JobData $job,
        array $context,
        bool $terminal
    ): array {
        $params = [
            'status' => $terminal ? 'failed' : 'pending',
            'attempts' => $job->attempts + 1,
            'error_message' => $context['error'],
            'available_at' => $context['now'],
            'updated_at' => $context['now'],
            'id' => $job->id,
            'threshold' => $context['threshold'],
        ];
        if ($terminal) {
            $params['completed_at'] = $context['now'];
        }
        return $params;
    }
}
