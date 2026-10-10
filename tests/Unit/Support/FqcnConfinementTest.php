<?php

declare(strict_types=1);

/**
 * Class-name string handling lives in `Support\Fqcn`. Outside it, src/ must not
 * strip a leading backslash with `ltrim(..., '\\')` / `Str::ltrim(..., '\\')`
 * nor declare class-name `normalize*` / `shortName` helpers. Keys are
 * `path/relative/to/src.php::name`; every entry needs a reason.
 *
 * @var array<string, string>
 */
const FQCN_HELPER_ALLOWLIST = [
    'Check/CheckContext.php::normalize' => 'reshapes an index section for rules, not a class name',
    'Query/Internal/SectionReader.php::normalise' => 'stringifies scalar/enum property values, not a class name',
    'Support/ClassHierarchyResolver.php::normalizeAdaptation' => 'rewrites a trait adaptation DTO; the class names inside go through Fqcn::normalize',
    'Support/RouteGroupAttributesBuilder.php::normaliseName' => 'route group name attribute, not a class name',
    'Support/RouteGroupAttributesBuilder.php::normalisePrefix' => 'route group URI prefix, not a class name',
    'Support/RoutePathResolver.php::normalise' => 'lexical filesystem path collapsing',
    'Support/ScanScope.php::normalisePath' => 'filesystem path normalisation',
    'Support/ScanScope.php::normaliseGlob' => 'filesystem glob normalisation',
];

/**
 * @return array<string, list<int>> offending `path::what` sites mapped to their line numbers
 */
function fqcnHelperSites(): array
{
    $root = realpath(__DIR__.'/../../../src');
    $found = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
        if (! str_ends_with((string) $file, '.php')) {
            continue;
        }

        $relative = str_replace('\\', '/', substr((string) $file, strlen($root) + 1));
        if ($relative === 'Support/Fqcn.php') {
            continue;
        }

        $found = array_merge_recursive($found, fqcnHelperSitesIn((string) file_get_contents((string) $file), $relative));
    }

    ksort($found);

    return $found;
}

/**
 * @return array<string, list<int>>
 */
function fqcnHelperSitesIn(string $source, string $relative): array
{
    $tokens = array_values(array_filter(
        token_get_all($source),
        fn (mixed $token): bool => ! is_array($token) || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
    ));
    $found = [];

    foreach ($tokens as $i => $token) {
        if (! is_array($token) || $token[0] !== T_STRING) {
            continue;
        }

        $name = $token[1];
        $declared = ($tokens[$i - 1][0] ?? null) === T_FUNCTION;

        // A declared `normalize*` / `normalise*` / `shortName` helper.
        if ($declared && preg_match('/^(normali[sz]e|shortName$)/i', $name) === 1) {
            $found["{$relative}::{$name}"][] = $token[2];

            continue;
        }

        // `ltrim(...)` or `Str::ltrim(...)` whose second argument is exactly a backslash literal.
        if (! $declared && strtolower($name) === 'ltrim' && ($tokens[$i + 1] ?? null) === '(' && fqcnSecondArgIsBackslash($tokens, $i + 1)) {
            $found["{$relative}::ltrim"][] = $token[2];
        }
    }

    return $found;
}

/**
 * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
 * @param  int  $open  index of the opening parenthesis
 */
function fqcnSecondArgIsBackslash(array $tokens, int $open): bool
{
    $depth = 0;
    $arg = 0;
    $second = [];

    for ($i = $open; $i < count($tokens); $i++) {
        $text = is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];

        if (in_array($text, ['(', '[', '{'], true)) {
            $depth++;

            continue;
        }
        if (in_array($text, [')', ']', '}'], true)) {
            $depth--;
            if ($depth === 0) {
                break;
            }

            continue;
        }
        if ($text === ',' && $depth === 1) {
            $arg++;

            continue;
        }
        if ($arg === 1) {
            $second[] = $text;
        }
    }

    return count($second) === 1 && in_array($second[0], ["'\\\\'", '"\\\\"'], true);
}

it('keeps class-name normalisation and short-name helpers inside Support\\Fqcn', function (): void {
    $offenders = array_diff_key(fqcnHelperSites(), FQCN_HELPER_ALLOWLIST);

    expect(array_keys($offenders))->toBe([], 'use Lucasp\\Loom\\Support\\Fqcn (normalize, short, namespaceOf, same, split*Member)');
});

it('drops allowlist entries once the helper is gone', function (): void {
    $stale = array_values(array_diff(array_keys(FQCN_HELPER_ALLOWLIST), array_keys(fqcnHelperSites())));

    expect($stale)->toBe([], 'remove these from FQCN_HELPER_ALLOWLIST');
});

it('requires a reason for every allowlist entry', function (): void {
    $blank = array_keys(array_filter(FQCN_HELPER_ALLOWLIST, fn (string $reason): bool => trim($reason) === ''));

    expect($blank)->toBe([]);
});

it('detects the patterns it guards against', function (): void {
    $bad = <<<'PHP'
    <?php
    $a = ltrim($x, '\\');
    $b = Str::ltrim($y, "\\");
    class A { private function normalize(string $s): string { return $s; } private function shortName(): string { return ''; } }
    PHP;
    $ok = <<<'PHP'
    <?php
    $a = ltrim($uri, '/');
    $b = Str::ltrim(Str::replace('\\', '/', $s), '/');
    PHP;

    expect(array_keys(fqcnHelperSitesIn($bad, 'x.php')))->toBe(['x.php::ltrim', 'x.php::normalize', 'x.php::shortName'])
        ->and(fqcnHelperSitesIn($ok, 'x.php'))->toBe([]);
});
