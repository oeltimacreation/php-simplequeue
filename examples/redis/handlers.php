<?php

declare(strict_types=1);

use Oeltima\SimpleQueue\Contract\JobHandlerInterface;

final class ExampleEmailHandler implements JobHandlerInterface
{
    /** @param array<string, mixed> $payload Job payload */
    public function handle(int $jobId, array $payload, ?callable $progress = null): mixed
    {
        $recipient = $payload['to'] ?? 'unknown';
        $recipient = is_string($recipient) ? $recipient : 'unknown';
        echo "Sending example email for job #{$jobId} to {$recipient}\n";
        if ($progress !== null) {
            $progress(100, 'Email sent');
        }

        return ['recipient' => $recipient, 'sent_at' => gmdate(DATE_ATOM)];
    }
}

final class ExampleReportHandler implements JobHandlerInterface
{
    /** @param array<string, mixed> $payload Job payload */
    public function handle(int $jobId, array $payload, ?callable $progress = null): mixed
    {
        $format = $payload['format'] ?? 'pdf';
        $format = is_string($format) ? $format : 'pdf';
        echo "Generating {$format} report for job #{$jobId}\n";
        if ($progress !== null) {
            $progress(100, 'Report generated');
        }

        return ['format' => $format, 'generated_at' => gmdate(DATE_ATOM)];
    }
}
