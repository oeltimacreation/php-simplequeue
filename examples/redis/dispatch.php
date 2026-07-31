<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

try {
    [, , $dispatcher, $queue] = createExampleQueue();
    $email = $dispatcher->dispatch('email.send', ['to' => 'ada@example.test'], $queue);
    $report = $dispatcher->dispatch('report.generate', ['format' => 'pdf'], $queue);

    // Scheduled dispatch: stored with a future available_at and added to the
    // queue's delayed ZSET. The worker promotes it when due (default promote
    // interval is 5s), then claims and processes it.
    $reminder = $dispatcher->dispatchAfter(5, 'email.send', ['to' => 'grace@example.test'], $queue);

    echo "Dispatched email job #{$email}, report job #{$report}, and scheduled email job #{$reminder} to {$queue}.\n";
    echo "The scheduled job #{$reminder} will be processed about 5 seconds after it is due.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, "Unable to dispatch: {$exception->getMessage()}\n");
    exit(1);
}
