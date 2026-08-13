<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Internal;

use Oeltima\SimpleQueue\Contract\JobData;
use Oeltima\SimpleQueue\Contract\JobStatus;

/**
 * Builds guarded failed-job transitions for PDO storage.
 *
 * @internal
 */
final class PdoFailedJobOperations
{
    private function __construct()
    {
    }

    /**
     * Reset a failed row to a fresh pending state.
     *
     * @param string $table Storage table name
     * @param string $now Current storage timestamp
     * @param int $jobId Job identifier
     * @param callable(string, array<string, mixed>): \PDOStatement $execute Statement executor
     * @param callable(int): (?JobData) $find Job lookup
     * @return JobData|null Reset job, or null when it is missing or not failed
     */
    public static function requeue(
        string $table,
        string $now,
        int $jobId,
        callable $execute,
        callable $find
    ): ?JobData {
        $sql = "UPDATE {$table}
            SET status = 'pending', attempts = 0, available_at = :available_at,
                started_at = NULL, completed_at = NULL, locked_by = NULL,
                locked_at = NULL, lease_token = NULL, error_message = NULL,
                error_trace = NULL, progress = NULL, progress_message = NULL,
                result = NULL, updated_at = :updated_at
            WHERE id = :id AND status = 'failed'";
        $statement = $execute($sql, [
            'available_at' => $now,
            'updated_at' => $now,
            'id' => $jobId,
        ]);

        return $statement->rowCount() > 0 ? $find($jobId) : null;
    }

    /**
     * Delete a failed row and return its last durable representation.
     *
     * @param string $table Storage table name
     * @param int $jobId Job identifier
     * @param callable(string, array<string, mixed>): \PDOStatement $execute Statement executor
     * @param callable(int): (?JobData) $find Job lookup
     * @return JobData|null Deleted job, or null when it is missing or not failed
     */
    public static function purge(
        string $table,
        int $jobId,
        callable $execute,
        callable $find
    ): ?JobData {
        $job = $find($jobId);
        if ($job === null || $job->status !== JobStatus::Failed) {
            return null;
        }

        $statement = $execute(
            "DELETE FROM {$table} WHERE id = :id AND status = 'failed'",
            ['id' => $jobId]
        );

        return $statement->rowCount() > 0 ? $job : null;
    }
}
