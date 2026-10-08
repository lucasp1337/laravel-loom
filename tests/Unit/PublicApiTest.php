<?php

declare(strict_types=1);

use Lucasp\Loom\Contracts\Scanner;

/**
 * @return array<string, string> FQCN => docblock text, for every type under src/
 */
function srcTypeDocblocks(): array
{
    $root = realpath(__DIR__.'/../../src');
    $types = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
        if (! str_ends_with((string) $file, '.php')) {
            continue;
        }

        $relative = substr((string) $file, strlen($root) + 1, -4);
        $fqcn = 'Lucasp\\Loom\\'.str_replace('/', '\\', $relative);

        $doc = (new ReflectionClass($fqcn))->getDocComment();
        $types[$fqcn] = $doc === false ? '' : $doc;
    }

    return $types;
}

function hasTag(string $doc, string $tag): bool
{
    return preg_match('/@'.$tag.'\b/', $doc) === 1;
}

it('marks every class under src as either @api or @internal, never both', function (): void {
    foreach (srcTypeDocblocks() as $fqcn => $doc) {
        $api = hasTag($doc, 'api');
        $internal = hasTag($doc, 'internal');

        expect($api xor $internal)->toBeTrue("{$fqcn} must carry exactly one of @api or @internal");
    }
});

it('lists every @api class in docs/reference/php-api.md', function (): void {
    $page = file_get_contents(__DIR__.'/../../docs/reference/php-api.md');

    foreach (srcTypeDocblocks() as $fqcn => $doc) {
        if (! hasTag($doc, 'api')) {
            continue;
        }

        $short = (new ReflectionClass($fqcn))->getShortName();

        expect(preg_match('/`'.preg_quote($short, '/').'`/', $page))
            ->toBe(1, "{$fqcn} is @api but not listed in php-api.md");
    }
});

it('keeps the scanner contract internal', function (): void {
    expect(hasTag((string) (new ReflectionClass(Scanner::class))->getDocComment(), 'internal'))->toBeTrue();
});
