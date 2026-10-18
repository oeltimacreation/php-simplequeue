<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Contract;

/** Compact monitoring data; never contains payload, result, trace or ownership secrets. */
final readonly class JobSummary
{
    /**
     * @param int $id Job ID
     * @param string $queue Queue name
     * @param string $type Job type
     * @param JobStatus $status Durable status
     * @param int $attempts Failed executions
     * @param int $maxAttempts Maximum failures
     * @param int|null $progress Latest persisted percentage
     * @param string|null $progressMessage Latest progress message
     * @param string $createdAt UTC creation timestamp
     * @param string $updatedAt UTC update timestamp
     */
    public function __construct(
        public int $id,
        public string $queue,
        public string $type,
        public JobStatus $status,
        public int $attempts,
        public int $maxAttempts,
        public ?int $progress,
        public ?string $progressMessage,
        public string $createdAt,
        public string $updatedAt
    ) {
    }

    /**
     * @param array<array-key, mixed>|object $data Compact storage projection
     * @return self Validated summary
     */
    public static function fromRaw(array|object $data): self
    {
        $row = (array) $data;
        $status = $row['status'] ?? null;
        $message = $row['progress_message'] ?? null;
        if ($message !== null && !is_string($message)) {
            throw new \UnexpectedValueException('Invalid summary progress message');
        }
        $progress = ($row['progress'] ?? null) === null ? null : self::integer($row, 'progress', 0);
        if ($progress !== null && $progress > 100) {
            throw new \UnexpectedValueException('Invalid summary progress');
        }
        return new self(
            self::integer($row, 'id', 1),
            self::text($row, 'queue'),
            self::text($row, 'type'),
            $status instanceof JobStatus ? $status : JobStatus::from(self::text($row, 'status')),
            self::integer($row, 'attempts', 0),
            self::integer($row, 'max_attempts', 1),
            $progress,
            $message,
            self::text($row, 'created_at'),
            self::text($row, 'updated_at')
        );
    }

    /** @param array<array-key, mixed> $row */
    private static function integer(array $row, string $key, int $minimum): int
    {
        $value = $row[$key] ?? null;
        if (!is_int($value) && !is_string($value)) {
            throw new \UnexpectedValueException('Invalid summary ' . $key);
        }
        $parsed = filter_var($value, FILTER_VALIDATE_INT);
        if ($parsed === false || $parsed < $minimum) {
            throw new \UnexpectedValueException('Invalid summary ' . $key);
        }
        return $parsed;
    }

    /** @param array<array-key, mixed> $row */
    private static function text(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new \UnexpectedValueException('Invalid summary ' . $key);
        }
        return $value;
    }
}
