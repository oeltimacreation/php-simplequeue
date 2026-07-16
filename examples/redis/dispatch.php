<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

try {
    [, , $dispatcher, $queue] = createExampleQueue();
    $email = $dispatcher->dispatch('email.send', ['to' => 'ada@example.test'], $queue);
    $report = $dispatcher->dispatch('report.generate', ['format' => 'pdf'], $queue);

    echo "Dispatched email job #{$email} and report job #{$report} to {$queue}.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, "Unable to dispatch: {$exception->getMessage()}\n");
    exit(1);
}
