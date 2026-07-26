<?php

declare(strict_types=1);

const QUALITY_SCHEMA_VERSION = 1;
const DUPLICATE_WINDOW_TOKENS = 50;
const CYCLOMATIC_TARGET = 15;
const COGNITIVE_TARGET = 15;
const NESTING_TARGET = 3;
const METHOD_SIZE_TARGET = 100;
const CLASS_SIZE_TARGET = 500;

$root = dirname(__DIR__);
$command = $argv[1] ?? 'report';
$baselinePath = $argv[2] ?? $root . '/quality/quality-baseline.json';
$exceptionsPath = $root . '/quality/ratchet-exceptions.json';
$inventory = buildInventory($root);

if ($command === 'report') {
    printReport($inventory);
    exit(0);
}

if ($command === 'write-baseline') {
    $storedInventory = $inventory;
    $storedInventory['duplicates'] = array_map(
        static function (array $duplicate): array {
            unset($duplicate['sample_occurrences']);
            return $duplicate;
        },
        $storedInventory['duplicates']
    );
    writeJson($baselinePath, $storedInventory);
    printf("Quality baseline written to %s\n", relativePath($root, $baselinePath));
    exit(0);
}

if ($command === 'check') {
    exit(checkRatchet([
        'root' => $root,
        'current' => $inventory,
        'baseline_path' => $baselinePath,
        'exceptions_path' => $exceptionsPath,
    ]));
}

fwrite(STDERR, "Usage: quality-metrics.php [report|write-baseline|check] [baseline.json]\n");
exit(2);

/**
 * @return array<string, mixed>
 */
function buildInventory(string $root): array
{
    $files = findPhpFiles($root);
    $classes = [];
    $methods = [];

    foreach ($files as $file) {
        $relative = relativePath($root, $file);
        $scope = str_starts_with($relative, 'src/') ? 'production' : 'tests';
        $parsed = parseFile(['path' => $file, 'relative' => $relative, 'scope' => $scope]);
        $classes = array_merge($classes, $parsed['classes']);
        $methods = array_merge($methods, $parsed['methods']);
    }

    ksort($classes);
    ksort($methods);
    $duplicates = findDuplicateWindows($methods);

    return [
        'schema_version' => QUALITY_SCHEMA_VERSION,
        'configuration' => [
            'duplicate_window_tokens' => DUPLICATE_WINDOW_TOKENS,
            'new_code_targets' => [
                'cyclomatic_complexity' => CYCLOMATIC_TARGET,
                'cognitive_complexity' => COGNITIVE_TARGET,
                'nesting_depth' => NESTING_TARGET,
                'method_lines' => METHOD_SIZE_TARGET,
                'class_lines' => CLASS_SIZE_TARGET,
            ],
        ],
        'summary' => summarize([
            'files' => $files,
            'inventory' => [
                'classes' => $classes,
                'methods' => $methods,
                'duplicates' => $duplicates,
            ],
            'root' => $root,
        ]),
        'classes' => $classes,
        'methods' => array_map(
            static function (array $method): array {
                unset($method['normalized_tokens']);
                return $method;
            },
            $methods
        ),
        'duplicates' => $duplicates,
    ];
}

/**
 * @return list<string>
 */
function findPhpFiles(string $root): array
{
    $files = [];
    foreach (['src', 'tests'] as $directory) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
    }
    sort($files);
    return $files;
}

/**
 * @param array{path: string, relative: string, scope: string} $file
 * @return array{classes: array<string, array<string, int|string>>, methods: array<string, array<string, mixed>>}
 */
function parseFile(array $file): array
{
    $source = file_get_contents($file['path']);
    if ($source === false) {
        throw new RuntimeException(sprintf('Could not read %s', $file['path']));
    }

    $rawTokens = token_get_all($source, TOKEN_PARSE);
    $tokens = normalizeTokenData($rawTokens);
    $context = [
        'tokens' => $tokens,
        'namespace' => findNamespace($tokens),
        'relative' => $file['relative'],
        'scope' => $file['scope'],
    ];
    $classes = [];
    $methods = [];
    for ($index = 0; $index < count($tokens); $index++) {
        $parsed = parseClassAt($context, $index);
        if ($parsed === null) {
            continue;
        }
        $classes[$parsed['key']] = $parsed['class'];
        $methods = array_merge($methods, $parsed['methods']);
        $index = $parsed['close'];
    }

    return ['classes' => $classes, 'methods' => $methods];
}

/**
 * @param array{tokens: list<array{id: int|null, text: string, line: int}>, namespace: string, relative: string, scope: string} $context
 * @return array{key: string, class: array<string, int|string>, methods: array<string, array<string, mixed>>, close: int}|null
 */
function parseClassAt(array $context, int $index): ?array
{
    $tokens = $context['tokens'];
    if (!isClassToken($tokens[$index]['id'])) {
        return null;
    }
    if (!isClassDeclaration($tokens, $index)) {
        return null;
    }
    $nameIndex = nextTokenOfType($tokens, $index + 1, T_STRING);
    if ($nameIndex === null) {
        return null;
    }
    $openBrace = nextTokenWithText($tokens, $nameIndex + 1, '{');
    if ($openBrace === null) {
        return null;
    }
    $closeBrace = matchingBrace($tokens, $openBrace);
    $className = ($context['namespace'] === '' ? '' : $context['namespace'] . '\\') . $tokens[$nameIndex]['text'];
    $classInfo = ['name' => $className, 'key' => $context['relative'] . '::' . $className];
    $methods = parseClassMethods($context, $classInfo, ['open' => $openBrace, 'close' => $closeBrace]);
    $startLine = $tokens[$index]['line'];
    $endLine = $tokens[$closeBrace]['line'];
    return [
        'key' => $classInfo['key'],
        'class' => [
            'scope' => $context['scope'],
            'file' => $context['relative'],
            'class' => $className,
            'start_line' => $startLine,
            'end_line' => $endLine,
            'lines' => $endLine - $startLine + 1,
            'methods' => count($methods),
        ],
        'methods' => $methods,
        'close' => $closeBrace,
    ];
}

/**
 * @param array{tokens: list<array{id: int|null, text: string, line: int}>, namespace: string, relative: string, scope: string} $context
 * @param array{name: string, key: string} $classInfo
 * @param array{open: int, close: int} $range
 * @return array<string, array<string, mixed>>
 */
function parseClassMethods(array $context, array $classInfo, array $range): array
{
    $methods = [];
    $depth = 1;
    for ($cursor = $range['open'] + 1; $cursor < $range['close']; $cursor++) {
        $depth += braceDelta($context['tokens'][$cursor]['text']);
        if ($depth !== 1 || $context['tokens'][$cursor]['id'] !== T_FUNCTION) {
            continue;
        }
        $parsed = parseMethodAt($context, $classInfo, $cursor);
        if ($parsed === null) {
            continue;
        }
        $methods[$parsed['key']] = $parsed['method'];
        $cursor = $parsed['close'];
    }
    return $methods;
}

/**
 * @param array{tokens: list<array{id: int|null, text: string, line: int}>, namespace: string, relative: string, scope: string} $context
 * @param array{name: string, key: string} $classInfo
 * @return array{key: string, method: array<string, mixed>, close: int}|null
 */
function parseMethodAt(array $context, array $classInfo, int $cursor): ?array
{
    $tokens = $context['tokens'];
    $nameIndex = nextSignificantToken($tokens, $cursor + 1);
    if ($nameIndex !== null && $tokens[$nameIndex]['text'] === '&') {
        $nameIndex = nextSignificantToken($tokens, $nameIndex + 1);
    }
    if ($nameIndex === null || $tokens[$nameIndex]['id'] !== T_STRING) {
        return null;
    }
    $openBrace = nextTokenWithText($tokens, $nameIndex + 1, '{', ';');
    if ($openBrace === null || $tokens[$openBrace]['text'] === ';') {
        return null;
    }
    $closeBrace = matchingBrace($tokens, $openBrace);
    $methodName = $tokens[$nameIndex]['text'];
    $range = ['tokens' => $tokens, 'open' => $openBrace, 'close' => $closeBrace, 'name' => $methodName];
    $metrics = calculateMethodMetrics($range);
    return [
        'key' => $classInfo['key'] . '::' . $methodName,
        'method' => [
            'scope' => $context['scope'],
            'file' => $context['relative'],
            'class' => $classInfo['name'],
            'method' => $methodName,
            'start_line' => $tokens[$cursor]['line'],
            'end_line' => $tokens[$closeBrace]['line'],
            'lines' => $tokens[$closeBrace]['line'] - $tokens[$cursor]['line'] + 1,
            'cyclomatic_complexity' => $metrics['cyclomatic_complexity'],
            'cognitive_complexity' => $metrics['cognitive_complexity'],
            'nesting_depth' => $metrics['nesting_depth'],
            'normalized_tokens' => normalizedMethodTokens($range),
        ],
        'close' => $closeBrace,
    ];
}

/**
 * @param array{tokens: list<array{id: int|null, text: string, line: int}>, open: int, close: int, name: string} $range
 * @return array{cyclomatic_complexity: int, cognitive_complexity: int, nesting_depth: int}
 */
function calculateMethodMetrics(array $range): array
{
    $state = [
        'cyclomatic' => 1,
        'cognitive' => 0,
        'control_nesting' => 0,
        'maximum_nesting' => 0,
        'pending_control' => false,
        'brace_controls' => [],
        'previous_boolean' => null,
    ];
    for ($index = $range['open'] + 1; $index < $range['close']; $index++) {
        updateCyclomatic($state, $range, $index);
        updateControlCognitive($state, $range, $index);
        updateBooleanCognitive($state, $range, $index);
        updateRecursiveCognitive($state, $range, $index);
        updateControlNesting($state, $range, $index);
    }
    return [
        'cyclomatic_complexity' => $state['cyclomatic'],
        'cognitive_complexity' => $state['cognitive'],
        'nesting_depth' => $state['maximum_nesting'],
    ];
}

/** @param array<string, mixed> $state @param array<string, mixed> $range */
function updateCyclomatic(array &$state, array $range, int $index): void
{
    $token = $range['tokens'][$index];
    $branches = [T_IF, T_ELSEIF, T_FOR, T_FOREACH, T_WHILE, T_CASE, T_CATCH];
    $booleans = [T_BOOLEAN_AND, T_BOOLEAN_OR, T_LOGICAL_AND, T_LOGICAL_OR, T_LOGICAL_XOR, T_COALESCE];
    $isTernary = $token['text'] === '?';
    if (($range['tokens'][$index + 1]['text'] ?? '') === '>') {
        $isTernary = false;
    }
    if (in_array(true, [in_array($token['id'], $branches, true), in_array($token['id'], $booleans, true), $isTernary], true)) {
        $state['cyclomatic']++;
    }
}

/** @param array<string, mixed> $state @param array<string, mixed> $range */
function updateControlCognitive(array &$state, array $range, int $index): void
{
    $id = $range['tokens'][$index]['id'];
    $controls = [T_IF, T_ELSEIF, T_FOR, T_FOREACH, T_WHILE, T_DO, T_SWITCH, T_CATCH];
    if (in_array($id, $controls, true)) {
        $state['cognitive'] += 1 + ($id === T_ELSEIF ? 0 : $state['control_nesting']);
        $state['pending_control'] = true;
        return;
    }
    if ($id === T_ELSE) {
        $state['cognitive']++;
        $state['pending_control'] = true;
    }
}

/** @param array<string, mixed> $state @param array<string, mixed> $range */
function updateBooleanCognitive(array &$state, array $range, int $index): void
{
    $token = $range['tokens'][$index];
    $booleans = [T_BOOLEAN_AND, T_BOOLEAN_OR, T_LOGICAL_AND, T_LOGICAL_OR, T_LOGICAL_XOR, T_COALESCE];
    if (in_array($token['id'], $booleans, true)) {
        if ($state['previous_boolean'] !== $token['id']) {
            $state['cognitive']++;
        }
        $state['previous_boolean'] = $token['id'];
        return;
    }
    if (!isSignificantToken($token)) {
        return;
    }
    if (in_array($token['text'], ['(', ')', '!'], true)) {
        return;
    }
    $state['previous_boolean'] = null;
}

/** @param array<string, mixed> $state @param array<string, mixed> $range */
function updateRecursiveCognitive(array &$state, array $range, int $index): void
{
    $token = $range['tokens'][$index];
    if ($token['id'] !== T_STRING) {
        return;
    }
    if (strcasecmp($token['text'], $range['name']) !== 0) {
        return;
    }
    $next = nextSignificantToken($range['tokens'], $index + 1);
    if ($next !== null && $range['tokens'][$next]['text'] === '(') {
        $state['cognitive']++;
    }
}

/** @param array<string, mixed> $state @param array<string, mixed> $range */
function updateControlNesting(array &$state, array $range, int $index): void
{
    $text = $range['tokens'][$index]['text'];
    if ($text === '{') {
        openMetricBrace($state);
        return;
    }
    if ($text === '}') {
        closeMetricBrace($state);
    }
}

/** @param array<string, mixed> $state */
function openMetricBrace(array &$state): void
{
    $state['brace_controls'][] = $state['pending_control'];
    if ($state['pending_control']) {
        $state['control_nesting']++;
        $state['maximum_nesting'] = max($state['maximum_nesting'], $state['control_nesting']);
    }
    $state['pending_control'] = false;
}

/** @param array<string, mixed> $state */
function closeMetricBrace(array &$state): void
{
    if (array_pop($state['brace_controls']) === true) {
        $state['control_nesting']--;
    }
}

/**
 * @param array{tokens: list<array{id: int|null, text: string, line: int}>, open: int, close: int, name: string} $range
 * @return list<array{value: string, line: int}>
 */
function normalizedMethodTokens(array $range): array
{
    $normalized = [];
    for ($index = $range['open'] + 1; $index < $range['close']; $index++) {
        $token = $range['tokens'][$index];
        if (!isSignificantToken($token)) {
            continue;
        }
        $value = match ($token['id']) {
            T_VARIABLE => '<variable>',
            T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_LNUMBER, T_DNUMBER => '<literal>',
            default => strtolower($token['text']),
        };
        $normalized[] = ['value' => $value, 'line' => $token['line']];
    }
    return $normalized;
}

/**
 * @param array<string, array<string, mixed>> $methods
 * @return list<array<string, mixed>>
 */
function findDuplicateWindows(array $methods): array
{
    $windows = [];
    foreach ($methods as $methodKey => $method) {
        $windows = array_merge_recursive($windows, duplicateWindowsForMethod($methodKey, $method));
    }

    $duplicates = [];
    foreach ($windows as $fingerprint => $occurrences) {
        $duplicate = duplicateSummary($fingerprint, $occurrences);
        if ($duplicate !== null) {
            $duplicates[] = $duplicate;
        }
    }

    usort($duplicates, static fn(array $left, array $right): int => $left['fingerprint'] <=> $right['fingerprint']);
    return $duplicates;
}

/**
 * @param array<string, mixed> $method
 * @return array<string, array<string, array<string, int|string>>>
 */
function duplicateWindowsForMethod(string $methodKey, array $method): array
{
    $windows = [];
    $tokens = $method['normalized_tokens'];
    $limit = count($tokens) - DUPLICATE_WINDOW_TOKENS;
    for ($offset = 0; $offset <= $limit; $offset++) {
        $values = array_column(array_slice($tokens, $offset, DUPLICATE_WINDOW_TOKENS), 'value');
        $fingerprint = hash('sha256', implode("\0", $values));
        $windows[$fingerprint][$methodKey . ':' . $offset] = [
            'method' => $methodKey,
            'scope' => $method['scope'],
            'line' => $tokens[$offset]['line'],
        ];
    }
    return $windows;
}

/** @param array<string, array<string, int|string>> $occurrences @return array<string, mixed>|null */
function duplicateSummary(string $fingerprint, array $occurrences): ?array
{
    if (count(array_unique(array_column($occurrences, 'method'))) < 2) {
        return null;
    }
    $scopes = array_values(array_unique(array_column($occurrences, 'scope')));
    sort($scopes);
    return [
        'fingerprint' => $fingerprint,
        'scope' => implode('+', $scopes),
        'token_count' => DUPLICATE_WINDOW_TOKENS,
        'occurrence_count' => count($occurrences),
        'sample_occurrences' => array_slice(array_values($occurrences), 0, 3),
    ];
}

/**
 * @param array{files: list<string>, inventory: array{classes: array<string, array<string, int|string>>, methods: array<string, array<string, mixed>>, duplicates: list<array<string, mixed>>}, root: string} $input
 * @return array<string, array<string, int>>
 */
function summarize(array $input): array
{
    $summary = [
        'production' => ['files' => 0, 'classes' => 0, 'methods' => 0, 'lines' => 0, 'duplicate_windows' => 0],
        'tests' => ['files' => 0, 'classes' => 0, 'methods' => 0, 'lines' => 0, 'duplicate_windows' => 0],
    ];
    foreach ($input['files'] as $file) {
        $scope = str_starts_with(relativePath($input['root'], $file), 'src/') ? 'production' : 'tests';
        $summary[$scope]['files']++;
        $lineCount = count(file($file) ?: []);
        $summary[$scope]['lines'] += $lineCount;
    }
    foreach ($input['inventory']['classes'] as $class) {
        $summary[$class['scope']]['classes']++;
    }
    foreach ($input['inventory']['methods'] as $method) {
        $summary[$method['scope']]['methods']++;
    }
    foreach ($input['inventory']['duplicates'] as $duplicate) {
        foreach (explode('+', $duplicate['scope']) as $scope) {
            $summary[$scope]['duplicate_windows']++;
        }
    }
    return $summary;
}

/**
 * @param array<string, mixed> $inventory
 */
function printReport(array $inventory): void
{
    foreach ($inventory['summary'] as $scope => $summary) {
        printf(
            "%s: %d files, %d classes, %d methods, %d lines, %d duplicated windows\n",
            ucfirst($scope),
            $summary['files'],
            $summary['classes'],
            $summary['methods'],
            $summary['lines'],
            $summary['duplicate_windows']
        );
    }

    $methods = array_values($inventory['methods']);
    usort($methods, static function (array $left, array $right): int {
        return [$right['cognitive_complexity'], $right['cyclomatic_complexity'], $right['lines']]
            <=> [$left['cognitive_complexity'], $left['cyclomatic_complexity'], $left['lines']];
    });
    echo "\nTop method hotspots:\n";
    foreach (array_slice($methods, 0, 20) as $method) {
        printf(
            "  cog=%2d cyclo=%2d nest=%d lines=%3d  %s::%s:%d\n",
            $method['cognitive_complexity'],
            $method['cyclomatic_complexity'],
            $method['nesting_depth'],
            $method['lines'],
            $method['file'],
            $method['method'],
            $method['start_line']
        );
    }

    $classes = array_values($inventory['classes']);
    usort($classes, static fn(array $left, array $right): int => $right['lines'] <=> $left['lines']);
    echo "\nLargest classes:\n";
    foreach (array_slice($classes, 0, 12) as $class) {
        printf("  lines=%4d methods=%2d  %s\n", $class['lines'], $class['methods'], $class['file']);
    }

    $duplicates = $inventory['duplicates'];
    usort(
        $duplicates,
        static fn(array $left, array $right): int => $right['occurrence_count'] <=> $left['occurrence_count']
    );
    echo "\nMost repeated normalized windows:\n";
    foreach (array_slice($duplicates, 0, 10) as $duplicate) {
        $locations = array_map(
            static fn(array $occurrence): string => $occurrence['method'] . ':' . $occurrence['line'],
            $duplicate['sample_occurrences']
        );
        printf(
            "  occurrences=%2d scope=%-16s %s\n",
            $duplicate['occurrence_count'],
            $duplicate['scope'],
            implode(', ', $locations)
        );
    }
}

/**
 * @param array{root: string, current: array<string, mixed>, baseline_path: string, exceptions_path: string} $input
 */
function checkRatchet(array $input): int
{
    $baseline = readJson($input['baseline_path']);
    $exceptions = is_file($input['exceptions_path']) ? readJson($input['exceptions_path']) : [];
    $results = [
        methodRatchetResults($input['current'], $baseline, $exceptions),
        classRatchetResults($input['current'], $baseline, $exceptions),
        duplicateRatchetResults($input['current'], $baseline, $exceptions),
    ];
    $failures = array_merge(...array_column($results, 'failures'));
    $allowed = array_merge(...array_column($results, 'allowed'));
    reportRatchetResults($allowed, $failures);
    if ($failures !== []) {
        return 1;
    }
    printf("Quality ratchet passed against %s\n", relativePath($input['root'], $input['baseline_path']));
    return 0;
}

/**
 * @param array<string, mixed> $current
 * @param array<string, mixed> $baseline
 * @param array<string, mixed> $exceptions
 * @return array{failures: list<string>, allowed: list<string>}
 */
function methodRatchetResults(array $current, array $baseline, array $exceptions): array
{
    $results = ['failures' => [], 'allowed' => []];
    $metricTargets = [
        'cyclomatic_complexity' => CYCLOMATIC_TARGET,
        'cognitive_complexity' => COGNITIVE_TARGET,
        'nesting_depth' => NESTING_TARGET,
        'lines' => METHOD_SIZE_TARGET,
    ];
    foreach ($current['methods'] as $key => $method) {
        $methodResults = methodMetricResults([
            'key' => $key,
            'method' => $method,
            'old' => $baseline['methods'][$key] ?? [],
            'targets' => $metricTargets,
            'exceptions' => $exceptions,
        ]);
        $results['failures'] = array_merge($results['failures'], $methodResults['failures']);
        $results['allowed'] = array_merge($results['allowed'], $methodResults['allowed']);
    }
    return $results;
}

/**
 * @param array{key: string, method: array<string, mixed>, old: array<string, mixed>, targets: array<string, int>, exceptions: array<string, mixed>} $input
 * @return array{failures: list<string>, allowed: list<string>}
 */
function methodMetricResults(array $input): array
{
    $results = ['failures' => [], 'allowed' => []];
    foreach ($input['targets'] as $metric => $target) {
        $limit = $input['old'][$metric] ?? $target;
        if ($input['method'][$metric] <= $limit) {
            continue;
        }
        appendMetricResult($results, ratchetMetricResult([
            'exceptions' => $input['exceptions'],
            'section' => 'methods',
            'key' => $input['key'],
            'metric' => $metric,
            'old' => $limit,
            'new' => $input['method'][$metric],
        ]));
    }
    return $results;
}

/**
 * @param array<string, mixed> $current
 * @param array<string, mixed> $baseline
 * @param array<string, mixed> $exceptions
 * @return array{failures: list<string>, allowed: list<string>}
 */
function classRatchetResults(array $current, array $baseline, array $exceptions): array
{
    $results = ['failures' => [], 'allowed' => []];
    foreach ($current['classes'] as $key => $class) {
        if ($class['scope'] !== 'production') {
            continue;
        }
        $limit = $baseline['classes'][$key]['lines'] ?? CLASS_SIZE_TARGET;
        if ($class['lines'] > $limit) {
            appendMetricResult($results, ratchetMetricResult([
                'exceptions' => $exceptions,
                'section' => 'classes',
                'key' => $key,
                'metric' => 'lines',
                'old' => $limit,
                'new' => $class['lines'],
            ]));
        }
    }
    return $results;
}

/**
 * @param array<string, mixed> $current
 * @param array<string, mixed> $baseline
 * @param array<string, mixed> $exceptions
 * @return array{failures: list<string>, allowed: list<string>}
 */
function duplicateRatchetResults(array $current, array $baseline, array $exceptions): array
{
    $results = ['failures' => [], 'allowed' => []];
    $baselineDuplicates = array_fill_keys(array_column($baseline['duplicates'], 'fingerprint'), true);
    foreach ($current['duplicates'] as $duplicate) {
        $fingerprint = $duplicate['fingerprint'];
        if (isset($baselineDuplicates[$fingerprint])) {
            continue;
        }
        $reason = $exceptions['duplicates'][$fingerprint]['reason'] ?? '';
        $description = sprintf('new duplicated %d-token window %s', DUPLICATE_WINDOW_TOKENS, $fingerprint);
        if (is_string($reason) && trim($reason) !== '') {
            $results['allowed'][] = $description . ': ' . $reason;
        } else {
            $results['failures'][] = $description;
        }
    }
    return $results;
}

/** @param list<string> $allowed @param list<string> $failures */
function reportRatchetResults(array $allowed, array $failures): void
{
    foreach ($allowed as $exception) {
        fwrite(STDOUT, 'Ratchet exception: ' . $exception . "\n");
    }
    if ($failures !== []) {
        foreach ($failures as $failure) {
            fwrite(STDERR, 'Quality ratchet failed: ' . $failure . "\n");
        }
        fwrite(STDERR, "Document intentional exceptions in quality/ratchet-exceptions.json.\n");
    }
}

/**
 * @param array{failures: list<string>, allowed: list<string>} $results
 * @param array{failure: string|null, allowed: string|null} $metricResult
 */
function appendMetricResult(array &$results, array $metricResult): void
{
    if ($metricResult['allowed'] !== null) {
        $results['allowed'][] = $metricResult['allowed'];
        return;
    }
    $results['failures'][] = $metricResult['failure'];
}

/** @param array<string, mixed> $input @return array{failure: string|null, allowed: string|null} */
function ratchetMetricResult(array $input): array
{
    $exception = $input['exceptions'][$input['section']][$input['key']] ?? [];
    $metrics = $exception['metrics'] ?? [];
    $reason = $exception['reason'] ?? '';
    $description = sprintf(
        '%s %s increased from %d to %d',
        $input['key'],
        $input['metric'],
        $input['old'],
        $input['new']
    );
    if (!is_array($metrics)) {
        return ['failure' => $description, 'allowed' => null];
    }
    $metricIsAllowed = in_array($input['metric'], $metrics, true);
    if (in_array('*', $metrics, true)) {
        $metricIsAllowed = true;
    }
    if (!$metricIsAllowed) {
        return ['failure' => $description, 'allowed' => null];
    }
    if (!is_string($reason) || trim($reason) === '') {
        return ['failure' => $description, 'allowed' => null];
    }
    return ['failure' => null, 'allowed' => $description . ': ' . $reason];
}

/**
 * @return array<string, mixed>
 */
function readJson(string $path): array
{
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException(sprintf('Could not read %s', $path));
    }
    $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new RuntimeException(sprintf('%s does not contain a JSON object', $path));
    }
    return $decoded;
}

/**
 * @param array<string, mixed> $data
 */
function writeJson(string $path, array $data): void
{
    $directory = dirname($path);
    if (!is_dir($directory)) {
        mkdir($directory, 0777, true);
    }
    if (!is_dir($directory)) {
        throw new RuntimeException(sprintf('Could not create %s', $directory));
    }
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    if (file_put_contents($path, $json) === false) {
        throw new RuntimeException(sprintf('Could not write %s', $path));
    }
}

/**
 * @param list<array{id: int|null, text: string, line: int}> $tokens
 */
function findNamespace(array $tokens): string
{
    $index = firstTokenOfType($tokens, T_NAMESPACE);
    if ($index === null) {
        return '';
    }
    return namespaceAfter($tokens, $index);
}

/** @param list<array{id: int|null, text: string, line: int}> $tokens */
function firstTokenOfType(array $tokens, int $type): ?int
{
    foreach ($tokens as $index => $token) {
        if ($token['id'] === $type) {
            return $index;
        }
    }
    return null;
}

/** @param list<array{id: int|null, text: string, line: int}> $tokens */
function namespaceAfter(array $tokens, int $index): string
{
    $parts = [];
    for ($cursor = $index + 1; isset($tokens[$cursor]); $cursor++) {
        if (in_array($tokens[$cursor]['text'], [';', '{'], true)) {
            break;
        }
        if (isSignificantToken($tokens[$cursor])) {
            $parts[] = $tokens[$cursor]['text'];
        }
    }
    return implode('', $parts);
}

/**
 * @param list<array{id: int|null, text: string, line: int}> $tokens
 */
function isClassDeclaration(array $tokens, int $index): bool
{
    $previous = previousSignificantToken($tokens, $index - 1);
    return $previous === null || !in_array($tokens[$previous]['id'], [T_NEW, T_DOUBLE_COLON], true);
}

function isClassToken(?int $id): bool
{
    return in_array($id, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true);
}

/**
 * @param list<array{id: int|null, text: string, line: int}> $tokens
 */
function nextTokenOfType(array $tokens, int $start, int $type): ?int
{
    for ($index = $start; isset($tokens[$index]); $index++) {
        if ($tokens[$index]['id'] === $type) {
            return $index;
        }
        if (in_array($tokens[$index]['text'], ['{', ';'], true)) {
            return null;
        }
    }
    return null;
}

/**
 * @param list<array{id: int|null, text: string, line: int}> $tokens
 */
function nextTokenWithText(array $tokens, int $start, string ...$choices): ?int
{
    for ($index = $start; isset($tokens[$index]); $index++) {
        if (in_array($tokens[$index]['text'], $choices, true)) {
            return $index;
        }
    }
    return null;
}

/**
 * @param list<array{id: int|null, text: string, line: int}> $tokens
 */
function nextSignificantToken(array $tokens, int $start): ?int
{
    for ($index = $start; isset($tokens[$index]); $index++) {
        if (isSignificantToken($tokens[$index])) {
            return $index;
        }
    }
    return null;
}

/**
 * @param list<array{id: int|null, text: string, line: int}> $tokens
 */
function previousSignificantToken(array $tokens, int $start): ?int
{
    for ($index = $start; $index >= 0; $index--) {
        if (isSignificantToken($tokens[$index])) {
            return $index;
        }
    }
    return null;
}

/**
 * @param array{id: int|null, text: string, line: int} $token
 */
function isSignificantToken(array $token): bool
{
    return !in_array($token['id'], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_CLOSE_TAG], true);
}

/**
 * @param list<array{id: int|null, text: string, line: int}> $tokens
 */
function matchingBrace(array $tokens, int $openBrace): int
{
    $depth = 0;
    for ($index = $openBrace; isset($tokens[$index]); $index++) {
        $depth += braceDelta($tokens[$index]['text']);
        if ($depth === 0) {
            return $index;
        }
    }
    throw new RuntimeException('Unmatched opening brace');
}

function braceDelta(string $token): int
{
    return match ($token) {
        '{' => 1,
        '}' => -1,
        default => 0,
    };
}

/**
 * @param list<array{0: int, 1: string, 2: int}|string> $rawTokens
 * @return list<array{id: int|null, text: string, line: int}>
 */
function normalizeTokenData(array $rawTokens): array
{
    $tokens = [];
    $line = 1;
    foreach ($rawTokens as $token) {
        if (is_array($token)) {
            $line = $token[2];
            $tokens[] = ['id' => $token[0], 'text' => $token[1], 'line' => $line];
            $line += substr_count($token[1], "\n");
        } else {
            $tokens[] = ['id' => null, 'text' => $token, 'line' => $line];
            $line += substr_count($token, "\n");
        }
    }
    return $tokens;
}

function relativePath(string $root, string $path): string
{
    $root = rtrim(str_replace('\\', '/', $root), '/');
    $path = str_replace('\\', '/', $path);
    return str_starts_with($path, $root . '/') ? substr($path, strlen($root) + 1) : $path;
}
