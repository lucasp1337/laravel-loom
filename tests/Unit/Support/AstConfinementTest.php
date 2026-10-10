<?php

declare(strict_types=1);

/**
 * Files under src/ outside Support/Ast/ that may still name php-parser's `Arg`
 * node, keyed by path relative to src/. Argument lists are read through
 * `Support\Ast\Args`; every entry needs a reason, and the list only shrinks.
 *
 * @var array<string, string>
 */
const AST_ARG_ALLOWLIST = [];

/** Matches `Node\Arg`, `PhpParser\Node\Arg` and a grouped `use PhpParser\Node\{Arg, ...}`. */
const AST_ARG_PATTERN = '/\bNode\\\\Arg\b|PhpParser\\\\Node\\\\\{[^}]*\bArg\b/';

/**
 * @return list<string> paths relative to src/ that reference php-parser's `Arg` node, Support/Ast excluded
 */
function srcFilesNamingNodeArg(): array
{
    $root = realpath(__DIR__.'/../../../src');
    $found = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
        if (! str_ends_with((string) $file, '.php')) {
            continue;
        }

        $relative = str_replace('\\', '/', substr((string) $file, strlen($root) + 1));
        if (str_starts_with($relative, 'Support/Ast/')) {
            continue;
        }

        if (preg_match(AST_ARG_PATTERN, (string) file_get_contents((string) $file)) === 1) {
            $found[] = $relative;
        }
    }

    sort($found);

    return $found;
}

it('keeps php-parser Arg nodes inside Support\\Ast', function (): void {
    $offenders = array_values(array_diff(srcFilesNamingNodeArg(), array_keys(AST_ARG_ALLOWLIST)));

    expect($offenders)->toBe([], 'read call arguments through Lucasp\\Loom\\Support\\Ast\\Args instead of Node\\Arg');
});

it('drops allowlist entries once their file no longer names Node\\Arg', function (): void {
    $stale = array_values(array_diff(array_keys(AST_ARG_ALLOWLIST), srcFilesNamingNodeArg()));

    expect($stale)->toBe([], 'remove these files from AST_ARG_ALLOWLIST');
});

it('requires a reason for every allowlist entry', function (): void {
    $blank = array_keys(array_filter(AST_ARG_ALLOWLIST, fn (string $reason): bool => trim($reason) === ''));

    expect($blank)->toBe([]);
});

it('detects the Arg patterns it guards against', function (): void {
    expect(preg_match(AST_ARG_PATTERN, '$x instanceof Node\\Arg'))->toBe(1)
        ->and(preg_match(AST_ARG_PATTERN, 'use PhpParser\\Node\\Arg;'))->toBe(1)
        ->and(preg_match(AST_ARG_PATTERN, 'use PhpParser\\Node\\{Expr, Arg};'))->toBe(1)
        ->and(preg_match(AST_ARG_PATTERN, 'use Lucasp\\Loom\\Support\\Ast\\Arg;'))->toBe(0)
        ->and(preg_match(AST_ARG_PATTERN, 'Node\\ArgPlaceholder'))->toBe(0);
});
