<?php

declare(strict_types=1);

require_once __DIR__ . '/quality-metrics.php';

$inventory = buildInventory(__DIR__ . '/fixtures/quality-metrics');

assertFixture(
    $inventory['methods']['src/complexity.php::ComplexityFixture::branch'] ?? null,
    static fn(array $method): bool => $method['cyclomatic_complexity'] === 3
        && $method['cognitive_complexity'] === 2
        && $method['nesting_depth'] === 1,
    'complexity fixture metrics'
);
assertFixture(
    $inventory['methods']['src/nesting.php::NestingFixture::nested'] ?? null,
    static fn(array $method): bool => $method['nesting_depth'] === 3,
    'nesting fixture metric'
);
assertFixture(
    $inventory['classes']['src/class-size.php::ClassSizeFixture'] ?? null,
    static fn(array $class): bool => $class['lines'] === 8,
    'class-size fixture metric'
);

$duplicateWindows = array_filter(
    $inventory['duplicates'],
    static fn(array $duplicate): bool => $duplicate['scope'] === 'production'
        && $duplicate['occurrence_count'] >= 2
);
if ($duplicateWindows === []) {
    throw new RuntimeException('duplicate-window fixture did not produce a shared fingerprint');
}

fwrite(STDOUT, "Quality analyzer fixtures passed\n");

/**
 * @param array<string, mixed>|null $value
 * @param Closure(array<string, mixed>): bool $predicate
 */
function assertFixture(?array $value, Closure $predicate, string $name): void
{
    if ($value === null || !$predicate($value)) {
        throw new RuntimeException(sprintf('Quality analyzer fixture failed: %s', $name));
    }
}
