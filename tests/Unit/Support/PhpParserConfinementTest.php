<?php

declare(strict_types=1);

/**
 * Directories (and files) under src/ allowed to import php-parser, relative
 * to src/. Everything else reads syntax trees through `Support\Ast`.
 *
 * @var list<string>
 */
const PHP_PARSER_HOMES = [
    'Support/Ast/',
    'Scanners/Visitors/',
];

/**
 * Files outside PHP_PARSER_HOMES that may still import php-parser, keyed by
 * path relative to src/. Every entry needs a reason; the list only shrinks.
 *
 * @var array<string, string>
 */
const PHP_PARSER_ALLOWLIST = [];

/** Matches `use PhpParser\...` and a fully qualified `\PhpParser\...` reference. */
const PHP_PARSER_PATTERN = '/^\s*use\s+PhpParser\\\\|\\\\PhpParser\\\\/m';

/**
 * @return list<string> paths relative to src/ that import php-parser, the homes excluded
 */
function srcFilesImportingPhpParser(): array
{
    $root = realpath(__DIR__.'/../../../src');
    $found = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
        if (! str_ends_with((string) $file, '.php')) {
            continue;
        }

        $relative = str_replace('\\', '/', substr((string) $file, strlen($root) + 1));
        if (collect(PHP_PARSER_HOMES)->contains(fn (string $home): bool => str_starts_with($relative, $home))) {
            continue;
        }

        if (preg_match(PHP_PARSER_PATTERN, (string) file_get_contents((string) $file)) === 1) {
            $found[] = $relative;
        }
    }

    sort($found);

    return $found;
}

it('keeps php-parser imports inside Support\\Ast and Scanners\\Visitors', function (): void {
    $offenders = array_values(array_diff(srcFilesImportingPhpParser(), array_keys(PHP_PARSER_ALLOWLIST)));

    expect($offenders)->toBe([], 'move the syntax-tree reading behind Lucasp\\Loom\\Support\\Ast or into a visitor');
});

it('drops allowlist entries once their file no longer imports php-parser', function (): void {
    $stale = array_values(array_diff(array_keys(PHP_PARSER_ALLOWLIST), srcFilesImportingPhpParser()));

    expect($stale)->toBe([], 'remove these files from PHP_PARSER_ALLOWLIST');
});

it('requires a reason for every allowlist entry', function (): void {
    $blank = array_keys(array_filter(PHP_PARSER_ALLOWLIST, fn (string $reason): bool => trim($reason) === ''));

    expect($blank)->toBe([]);
});

it('detects the php-parser imports it guards against', function (): void {
    expect(preg_match(PHP_PARSER_PATTERN, "use PhpParser\\Node;\n"))->toBe(1)
        ->and(preg_match(PHP_PARSER_PATTERN, "use PhpParser\\Node\\Expr;\n"))->toBe(1)
        ->and(preg_match(PHP_PARSER_PATTERN, '$x instanceof \\PhpParser\\Node'))->toBe(1)
        ->and(preg_match(PHP_PARSER_PATTERN, "use Lucasp\\Loom\\Support\\Ast\\Args;\n"))->toBe(0);
});
