<?php

declare(strict_types=1);

use Oeltima\SimpleQueue\Contract\JobHandlerInterface;

final class ExampleEmailHandler implements JobHandlerInterface
{
    public function handle(int $jobId, array $payload, ?callable $progress = null): mixed
    {
        $recipient = (string) ($payload['to'] ?? 'unknown');
        echo "Sending example email for job #{$jobId} to {$recipient}\n";
        $progress?->__invoke(100, 'Email sent');

        return ['recipient' => $recipient, 'sent_at' => gmdate(DATE_ATOM)];
    }
}

final class ExampleReportHandler implements JobHandlerInterface
{
    public function handle(int $jobId, array $payload, ?callable $progress = null): mixed
    {
        $format = (string) ($payload['format'] ?? 'pdf');
        echo "Generating {$format} report for job #{$jobId}\n";
        $progress?->__invoke(100, 'Report generated');

        return ['format' => $format, 'generated_at' => gmdate(DATE_ATOM)];
    }
}
