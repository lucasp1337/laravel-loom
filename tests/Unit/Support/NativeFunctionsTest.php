<?php

declare(strict_types=1);

/**
 * `Illuminate\Support` (`Arr`, `Str`, `Collection`) is the house style in src/. A native call
 * below is only allowed where recorded in the allowlist with a reason.
 */
const FORBIDDEN_NATIVE_FUNCTIONS = [
    'str_contains', 'str_starts_with', 'str_ends_with', 'str_replace', 'mb_strtolower',
    'implode', 'array_key_exists',
    'array_map', 'array_filter', 'array_unique', 'array_merge', 'array_slice', 'array_reverse',
    'array_flip', 'array_column', 'array_combine', 'array_fill_keys',
    'array_diff', 'array_diff_key', 'array_diff_assoc', 'array_intersect', 'array_intersect_key',
    'array_search', 'in_array', 'array_sum', 'array_reduce', 'array_chunk',
    'sort', 'rsort', 'usort', 'asort', 'arsort', 'uasort', 'ksort', 'krsort', 'uksort',
];

/**
 * "path-or-directory-prefix::function" => reason. Every entry must still match a call.
 *
 * @var array<string, string>
 */
const NATIVE_FUNCTION_ALLOWLIST = [
    'src/Console/ShowCommand.php::str_contains' => 'the needle is user input and may be empty; Str::contains returns false for an empty needle, native returns true',
    'src/Index/Index.php::array_map' => '$factory is a callable of unknown arity; Arr::map would pass the key as a second argument',
    'src/Ui/Livewire/ChainPage.php::array_filter' => 'defensive is_string filter over client-hydrated Livewire state; Arr::where passes ($value, $key) and PHPStan narrows the closure parameter away',
    'src/Support/AstHelpers.php::in_array' => 'called per AST node; collect() would allocate a Collection per call',
    'src/Support/ClassHierarchyResolver.php::in_array' => 'called per class lookup while walking hierarchies; collect() would allocate per call',
    'src/Scanners/Visitors/::array_map' => 'visitor code runs per AST node; helpers allocate per call',
    'src/Scanners/Visitors/::in_array' => 'visitor code runs per AST node; collect() would allocate a Collection per call',
    'src/Scanners/Visitors/::array_slice' => 'visitor code runs per AST node; collect() would allocate a Collection per call',
    'src/Scanners/Visitors/::array_reverse' => 'visitor code runs per AST node; collect() would allocate a Collection per call',
    'src/Scanners/Visitors/::array_unique' => 'visitor code runs per AST node; collect() would allocate a Collection per call',
];

/** @return array<string, list<string>> "relative path::function" => call sites */
function nativeFunctionCalls(): array
{
    $root = dirname(__DIR__, 3);
    $found = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/src', FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }
        $tokens = array_values(array_filter(
            PhpToken::tokenize((string) file_get_contents($file->getPathname())),
            static fn (PhpToken $t): bool => ! $t->isIgnorable(),
        ));
        foreach ($tokens as $i => $token) {
            if (! $token->is(T_STRING) || ! in_array(strtolower($token->text), FORBIDDEN_NATIVE_FUNCTIONS, true)) {
                continue;
            }
            $next = $tokens[$i + 1] ?? null;
            $prev = $tokens[$i - 1] ?? null;
            $isMember = $prev !== null && $prev->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION]);
            if ($next !== null && $next->text === '(' && ! $isMember) {
                $relative = ltrim(str_replace($root, '', $file->getPathname()), '/');
                $found[$relative.'::'.strtolower($token->text)][] = $token->line.'';
            }
        }
    }

    return $found;
}

/** True when the allowlist key (file or directory prefix) covers the "path::function" site. */
function nativeFunctionAllowed(string $site, string $allowKey): bool
{
    [$allowPath, $allowFn] = explode('::', $allowKey);
    [$path, $fn] = explode('::', $site);

    return $fn === $allowFn && ($path === $allowPath || (str_ends_with($allowPath, '/') && str_starts_with($path, $allowPath)));
}

it('uses Illuminate Support helpers instead of native array, string and sort functions in src', function (): void {
    $violations = [];
    foreach (nativeFunctionCalls() as $site => $lines) {
        $allowed = array_filter(
            array_keys(NATIVE_FUNCTION_ALLOWLIST),
            static fn (string $key): bool => nativeFunctionAllowed($site, $key),
        );
        if ($allowed === []) {
            $violations[$site] = $lines;
        }
    }

    expect($violations)->toBe([], 'Use Illuminate\\Support Arr/Str/Collection, or allowlist the call with a reason.');
});

it('keeps the native function allowlist free of stale entries', function (): void {
    $sites = array_keys(nativeFunctionCalls());

    foreach (NATIVE_FUNCTION_ALLOWLIST as $key => $reason) {
        expect($reason)->not->toBe('');
        $hits = array_filter($sites, static fn (string $site): bool => nativeFunctionAllowed($site, $key));
        expect($hits)->not->toBe([], "Stale allowlist entry: {$key}");
    }
});
