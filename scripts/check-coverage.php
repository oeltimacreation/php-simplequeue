<?php

declare(strict_types=1);

$rawArguments = $_SERVER['argv'] ?? null;
$arguments = [];
if (is_array($rawArguments)) {
    foreach ($rawArguments as $argument) {
        if (!is_string($argument)) {
            $arguments = [];
            break;
        }
        $arguments[] = $argument;
    }
}
$argumentCount = count($arguments);

if ($argumentCount < 3 || $argumentCount > 4) {
    fwrite(STDERR, "Usage: check-coverage.php <clover.xml> <min-lines> [min-methods]\n");
    exit(2);
}

$report = simplexml_load_file($arguments[1]);
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
$lineCoverage = ($covered / $statements) * 100;
$minLines = (float) $arguments[2];
if ($lineCoverage < $minLines) {
    fwrite(STDERR, sprintf("Line coverage %.2f%% is below %.2f%%\n", $lineCoverage, $minLines));
    exit(1);
}

printf("Line coverage %.2f%% meets %.2f%% threshold\n", $lineCoverage, $minLines);

if ($argumentCount === 4) {
    $methods = isset($metrics['methods']) ? (int) $metrics['methods'] : 0;
    $coveredMethods = isset($metrics['coveredmethods']) ? (int) $metrics['coveredmethods'] : 0;
    if ($methods === 0) {
        fwrite(STDERR, "Clover report contains no methods\n");
        exit(2);
    }
    $methodCoverage = ($coveredMethods / $methods) * 100;
    $minMethods = (float) $arguments[3];
    if ($methodCoverage < $minMethods) {
        fwrite(STDERR, sprintf("Method coverage %.2f%% is below %.2f%%\n", $methodCoverage, $minMethods));
        exit(1);
    }
    printf("Method coverage %.2f%% meets %.2f%% threshold\n", $methodCoverage, $minMethods);
}
