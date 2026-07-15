<?php

declare(strict_types=1);

if ($argc !== 3) {
    fwrite(STDERR, "Usage: check-coverage.php <clover.xml> <minimum-percent>\n");
    exit(2);
}

$report = simplexml_load_file($argv[1]);
if ($report === false || !isset($report->project->metrics)) {
    fwrite(STDERR, "Could not read Clover coverage report\n");
    exit(2);
}

$metrics = $report->project->metrics->attributes();
$statements = isset($metrics['statements']) ? (int) $metrics['statements'] : 0;
$covered = isset($metrics['coveredstatements']) ? (int) $metrics['coveredstatements'] : 0;
if ($statements === 0) {
    fwrite(STDERR, "Clover report contains no statements\n");
    exit(2);
}
$coverage = ($covered / $statements) * 100;
$minimum = (float) $argv[2];
if ($coverage < $minimum) {
    fwrite(STDERR, sprintf("Line coverage %.2f%% is below %.2f%%\n", $coverage, $minimum));
    exit(1);
}

printf("Line coverage %.2f%% meets %.2f%% threshold\n", $coverage, $minimum);
