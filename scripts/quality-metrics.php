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
    exit(checkRatchet($root, $inventory, $baselinePath, $exceptionsPath));
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
        $parsed = parseFile($file, $relative, $scope);
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
        'summary' => summarize($files, $classes, $methods, $duplicates, $root),
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
 * @return array{classes: array<string, array<string, int|string>>, methods: array<string, array<string, mixed>>}
 */
function parseFile(string $file, string $relative, string $scope): array
{
    $source = file_get_contents($file);
    if ($source === false) {
        throw new RuntimeException(sprintf('Could not read %s', $file));
    }

    $rawTokens = token_get_all($source, TOKEN_PARSE);
    $tokens = normalizeTokenData($rawTokens);
    $namespace = findNamespace($tokens);
    $classes = [];
    $methods = [];
    $tokenCount = count($tokens);

    for ($index = 0; $index < $tokenCount; $index++) {
        if (!isClassToken($tokens[$index]['id']) || !isClassDeclaration($tokens, $index)) {
            continue;
        }

        $nameIndex = nextTokenOfType($tokens, $index + 1, T_STRING);
        $openBrace = nextTokenWithText($tokens, $nameIndex + 1, '{');
        if ($nameIndex === null || $openBrace === null) {
            continue;
        }
        $closeBrace = matchingBrace($tokens, $openBrace);
        $className = ($namespace === '' ? '' : $namespace . '\\') . $tokens[$nameIndex]['text'];
        $classKey = $relative . '::' . $className;
        $classStart = $tokens[$index]['line'];
        $classEnd = $tokens[$closeBrace]['line'];
        $classMethods = 0;

        $depth = 1;
        for ($cursor = $openBrace + 1; $cursor < $closeBrace; $cursor++) {
            $text = $tokens[$cursor]['text'];
            if ($text === '{') {
                $depth++;
                continue;
            }
            if ($text === '}') {
                $depth--;
                continue;
            }
            if ($depth !== 1 || $tokens[$cursor]['id'] !== T_FUNCTION) {
                continue;
            }

            $methodNameIndex = nextSignificantToken($tokens, $cursor + 1);
            if ($methodNameIndex !== null && $tokens[$methodNameIndex]['text'] === '&') {
                $methodNameIndex = nextSignificantToken($tokens, $methodNameIndex + 1);
            }
            if ($methodNameIndex === null || $tokens[$methodNameIndex]['id'] !== T_STRING) {
                continue;
            }

            $methodOpenBrace = nextTokenWithText($tokens, $methodNameIndex + 1, '{', ';');
            if ($methodOpenBrace === null || $tokens[$methodOpenBrace]['text'] === ';') {
                continue;
            }
            $methodCloseBrace = matchingBrace($tokens, $methodOpenBrace);
            $methodName = $tokens[$methodNameIndex]['text'];
            $methodKey = $classKey . '::' . $methodName;
            $metrics = calculateMethodMetrics($tokens, $methodOpenBrace, $methodCloseBrace, $methodName);
            $methods[$methodKey] = [
                'scope' => $scope,
                'file' => $relative,
                'class' => $className,
                'method' => $methodName,
                'start_line' => $tokens[$cursor]['line'],
                'end_line' => $tokens[$methodCloseBrace]['line'],
                'lines' => $tokens[$methodCloseBrace]['line'] - $tokens[$cursor]['line'] + 1,
                'cyclomatic_complexity' => $metrics['cyclomatic_complexity'],
                'cognitive_complexity' => $metrics['cognitive_complexity'],
                'nesting_depth' => $metrics['nesting_depth'],
                'normalized_tokens' => normalizedMethodTokens($tokens, $methodOpenBrace + 1, $methodCloseBrace),
            ];
            $classMethods++;
            $cursor = $methodCloseBrace;
        }

        $classes[$classKey] = [
            'scope' => $scope,
            'file' => $relative,
            'class' => $className,
            'start_line' => $classStart,
            'end_line' => $classEnd,
            'lines' => $classEnd - $classStart + 1,
            'methods' => $classMethods,
        ];
        $index = $closeBrace;
    }

    return ['classes' => $classes, 'methods' => $methods];
}

/**
 * @param list<array{id: int|null, text: string, line: int}> $tokens
 * @return array{cyclomatic_complexity: int, cognitive_complexity: int, nesting_depth: int}
 */
function calculateMethodMetrics(array $tokens, int $start, int $end, string $methodName): array
{
    $cyclomatic = 1;
    $cognitive = 0;
    $controlNesting = 0;
    $maximumNesting = 0;
    $pendingControl = false;
    $braceControls = [];
    $previousBooleanOperator = null;

    $branchTokens = [T_IF, T_ELSEIF, T_FOR, T_FOREACH, T_WHILE, T_CASE, T_CATCH];
    $nestedControlTokens = [T_IF, T_ELSEIF, T_FOR, T_FOREACH, T_WHILE, T_DO, T_SWITCH, T_CATCH];
    $booleanTokens = [T_BOOLEAN_AND, T_BOOLEAN_OR, T_LOGICAL_AND, T_LOGICAL_OR, T_LOGICAL_XOR, T_COALESCE];

    for ($index = $start + 1; $index < $end; $index++) {
        $token = $tokens[$index];
        $id = $token['id'];
        $text = $token['text'];

        if ($id !== null && in_array($id, $branchTokens, true)) {
            $cyclomatic++;
        } elseif ($text === '?' && ($tokens[$index + 1]['text'] ?? '') !== '>') {
            $cyclomatic++;
        } elseif ($id !== null && in_array($id, $booleanTokens, true)) {
            $cyclomatic++;
        }

        if ($id !== null && in_array($id, $nestedControlTokens, true)) {
            $isElseIf = $id === T_ELSEIF;
            $cognitive += 1 + ($isElseIf ? 0 : $controlNesting);
            $pendingControl = true;
        } elseif ($id === T_ELSE) {
            $cognitive++;
            $pendingControl = true;
        }

        if ($id !== null && in_array($id, $booleanTokens, true)) {
            if ($previousBooleanOperator !== $id) {
                $cognitive++;
                $previousBooleanOperator = $id;
            }
        } elseif (isSignificantToken($token) && !in_array($text, ['(', ')', '!'], true)) {
            $previousBooleanOperator = null;
        }

        if ($id === T_STRING && strcasecmp($text, $methodName) === 0) {
            $next = nextSignificantToken($tokens, $index + 1);
            if ($next !== null && $tokens[$next]['text'] === '(') {
                $cognitive++;
            }
        }

        if ($text === '{') {
            $braceControls[] = $pendingControl;
            if ($pendingControl) {
                $controlNesting++;
                $maximumNesting = max($maximumNesting, $controlNesting);
            }
            $pendingControl = false;
        } elseif ($text === '}') {
            $wasControl = array_pop($braceControls);
            if ($wasControl === true) {
                $controlNesting--;
            }
        }
    }

    return [
        'cyclomatic_complexity' => $cyclomatic,
        'cognitive_complexity' => $cognitive,
        'nesting_depth' => $maximumNesting,
    ];
}

/**
 * @param list<array{id: int|null, text: string, line: int}> $tokens
 * @return list<array{value: string, line: int}>
 */
function normalizedMethodTokens(array $tokens, int $start, int $end): array
{
    $normalized = [];
    for ($index = $start; $index < $end; $index++) {
        $token = $tokens[$index];
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
    }

    $duplicates = [];
    foreach ($windows as $fingerprint => $occurrences) {
        $uniqueMethods = array_unique(array_column($occurrences, 'method'));
        if (count($uniqueMethods) < 2) {
            continue;
        }
        $scopes = array_values(array_unique(array_column($occurrences, 'scope')));
        sort($scopes);
        $duplicates[] = [
            'fingerprint' => $fingerprint,
            'scope' => implode('+', $scopes),
            'token_count' => DUPLICATE_WINDOW_TOKENS,
            'occurrence_count' => count($occurrences),
            'sample_occurrences' => array_slice(array_values($occurrences), 0, 3),
        ];
    }

    usort($duplicates, static fn(array $left, array $right): int => $left['fingerprint'] <=> $right['fingerprint']);
    return $duplicates;
}

/**
 * @param list<string> $files
 * @param array<string, array<string, int|string>> $classes
 * @param array<string, array<string, mixed>> $methods
 * @param list<array<string, mixed>> $duplicates
 * @return array<string, array<string, int>>
 */
function summarize(array $files, array $classes, array $methods, array $duplicates, string $root): array
{
    $summary = [
        'production' => ['files' => 0, 'classes' => 0, 'methods' => 0, 'lines' => 0, 'duplicate_windows' => 0],
        'tests' => ['files' => 0, 'classes' => 0, 'methods' => 0, 'lines' => 0, 'duplicate_windows' => 0],
    ];
    foreach ($files as $file) {
        $scope = str_starts_with(relativePath($root, $file), 'src/') ? 'production' : 'tests';
        $summary[$scope]['files']++;
        $lineCount = count(file($file) ?: []);
        $summary[$scope]['lines'] += $lineCount;
    }
    foreach ($classes as $class) {
        $summary[$class['scope']]['classes']++;
    }
    foreach ($methods as $method) {
        $summary[$method['scope']]['methods']++;
    }
    foreach ($duplicates as $duplicate) {
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
 * @param array<string, mixed> $current
 */
function checkRatchet(string $root, array $current, string $baselinePath, string $exceptionsPath): int
{
    $baseline = readJson($baselinePath);
    $exceptions = is_file($exceptionsPath) ? readJson($exceptionsPath) : [];
    $failures = [];
    $allowed = [];
    $metricTargets = [
        'cyclomatic_complexity' => CYCLOMATIC_TARGET,
        'cognitive_complexity' => COGNITIVE_TARGET,
        'nesting_depth' => NESTING_TARGET,
        'lines' => METHOD_SIZE_TARGET,
    ];

    foreach ($current['methods'] as $key => $method) {
        $old = $baseline['methods'][$key] ?? null;
        foreach ($metricTargets as $metric => $target) {
            $limit = $old[$metric] ?? $target;
            if ($method[$metric] <= $limit) {
                continue;
            }
            recordRatchetResult($failures, $allowed, $exceptions, 'methods', $key, $metric, $limit, $method[$metric]);
        }
    }

    foreach ($current['classes'] as $key => $class) {
        if ($class['scope'] !== 'production') {
            continue;
        }
        $limit = $baseline['classes'][$key]['lines'] ?? CLASS_SIZE_TARGET;
        if ($class['lines'] > $limit) {
            recordRatchetResult($failures, $allowed, $exceptions, 'classes', $key, 'lines', $limit, $class['lines']);
        }
    }

    $baselineDuplicates = array_fill_keys(array_column($baseline['duplicates'], 'fingerprint'), true);
    foreach ($current['duplicates'] as $duplicate) {
        $fingerprint = $duplicate['fingerprint'];
        if (isset($baselineDuplicates[$fingerprint])) {
            continue;
        }
        $reason = $exceptions['duplicates'][$fingerprint]['reason'] ?? '';
        $description = sprintf('new duplicated %d-token window %s', DUPLICATE_WINDOW_TOKENS, $fingerprint);
        if (is_string($reason) && trim($reason) !== '') {
            $allowed[] = $description . ': ' . $reason;
        } else {
            $failures[] = $description;
        }
    }

    foreach ($allowed as $exception) {
        fwrite(STDOUT, 'Ratchet exception: ' . $exception . "\n");
    }
    if ($failures !== []) {
        foreach ($failures as $failure) {
            fwrite(STDERR, 'Quality ratchet failed: ' . $failure . "\n");
        }
        fwrite(STDERR, "Document intentional exceptions in quality/ratchet-exceptions.json.\n");
        return 1;
    }

    printf("Quality ratchet passed against %s\n", relativePath($root, $baselinePath));
    return 0;
}

/**
 * @param list<string> $failures
 * @param list<string> $allowed
 * @param array<string, mixed> $exceptions
 */
function recordRatchetResult(
    array &$failures,
    array &$allowed,
    array $exceptions,
    string $section,
    string $key,
    string $metric,
    int $oldValue,
    int $newValue
): void {
    $exception = $exceptions[$section][$key] ?? [];
    $metrics = $exception['metrics'] ?? [];
    $reason = $exception['reason'] ?? '';
    $description = sprintf('%s %s increased from %d to %d', $key, $metric, $oldValue, $newValue);
    if (
        is_array($metrics) && (in_array($metric, $metrics, true) || in_array('*', $metrics, true))
        && is_string($reason) && trim($reason) !== ''
    ) {
        $allowed[] = $description . ': ' . $reason;
        return;
    }
    $failures[] = $description;
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
    if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
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
    foreach ($tokens as $index => $token) {
        if ($token['id'] !== T_NAMESPACE) {
            continue;
        }
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
    return '';
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
        if ($tokens[$index]['text'] === '{') {
            $depth++;
        } elseif ($tokens[$index]['text'] === '}') {
            $depth--;
            if ($depth === 0) {
                return $index;
            }
        }
    }
    throw new RuntimeException('Unmatched opening brace');
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
