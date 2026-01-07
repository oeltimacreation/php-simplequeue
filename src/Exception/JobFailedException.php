<?php

declare(strict_types=1);

namespace Oeltima\SimpleQueue\Exception;

/**
 * Exception thrown when a job fails during execution.
 */
class JobFailedException extends QueueException
{
    private int $jobId;
    private string $jobType;

    public function __construct(int $jobId, string $jobType, string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->jobId = $jobId;
        $this->jobType = $jobType;
    }

    public function getJobId(): int
    {
        return $this->jobId;
    }

    public function getJobType(): string
    {
        return $this->jobType;
    }
}
