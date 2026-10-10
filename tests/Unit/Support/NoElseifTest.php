<?php

declare(strict_types=1);

/**
 * Files under src/ that still contain `elseif` / `else if`, keyed by path
 * relative to src/. New code uses `match`, enums and guard clauses instead.
 * Remove an entry when its file is refactored.
 *
 * @var array<string, string>
 */
const ELSEIF_ALLOWLIST = [
    'Check/DispatchGraph.php' => 'legacy, refactor tracked',
    'Check/Rules/UnresolvedDispatchesRule.php' => 'legacy, refactor tracked',
    'Scanners/Visitors/DispatchSiteVisitor.php' => 'legacy, refactor tracked',
    'Scanners/Visitors/ObserveCallVisitor.php' => 'legacy, refactor tracked',
    'Support/ScanScope.php' => 'legacy, refactor tracked',
];

/**
 * @return array<string, int> `elseif` / `else if` count by path relative to src/
 */
function elseifCounts(): array
{
    $root = realpath(__DIR__.'/../../../src');
    $counts = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
        if (! str_ends_with((string) $file, '.php')) {
            continue;
        }

        $tokens = array_values(array_filter(
            token_get_all((string) file_get_contents((string) $file)),
            fn (mixed $token): bool => ! is_array($token) || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));

        $found = 0;
        foreach ($tokens as $index => $token) {
            $isElseif = is_array($token) && $token[0] === T_ELSEIF;
            $isElseIf = is_array($token) && $token[0] === T_ELSE
                && is_array($tokens[$index + 1] ?? null) && $tokens[$index + 1][0] === T_IF;

            if ($isElseif || $isElseIf) {
                $found++;
            }
        }

        if ($found > 0) {
            $counts[str_replace('\\', '/', substr((string) $file, strlen($root) + 1))] = $found;
        }
    }

    ksort($counts);

    return $counts;
}

it('keeps elseif chains out of src: use match, enums and guard clauses', function (): void {
    $offenders = array_diff_key(elseifCounts(), ELSEIF_ALLOWLIST);

    expect($offenders)->toBe([], 'elseif / else if found; use match, enums or guard clauses with a comment per branch');
});

it('drops allowlist entries once their file no longer has an elseif', function (): void {
    $stale = array_diff_key(ELSEIF_ALLOWLIST, elseifCounts());

    expect($stale)->toBe([], 'remove these files from ELSEIF_ALLOWLIST');
});
