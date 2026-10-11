<?php

declare(strict_types=1);

use Lucasp\Loom\Tools\ReferencePages;

/**
 * The schema, MCP tool and config reference pages are generated from the JSON
 * schema, the tool classes and config/loom.php. Fails when a committed page
 * differs from a fresh render; fix it with `composer docs:generate`.
 */
it('keeps the generated reference regions in sync with their sources', function (): void {
    $root = dirname(__DIR__, 2);
    $stale = [];

    foreach ((new ReferencePages($root))->render() as $page => $expected) {
        if ($expected !== file_get_contents($root.'/'.$page)) {
            $stale[] = $page;
        }
    }

    expect($stale)->toBe([], 'Run `composer docs:generate` and commit the result.');
});

it('lists every config key in a generated config table', function (): void {
    $root = dirname(__DIR__, 2);
    $config = require $root.'/config/loom.php';
    $pages = (new ReferencePages($root))->render();
    $haystack = $pages['docs/reference/scan-config.md']."\n".$pages['docs/reference/ui-config.md'];

    $missing = [];
    foreach ($config as $group => $value) {
        $keys = is_array($value) && ! array_is_list($value)
            ? array_map(static fn (string $key): string => "{$group}.{$key}", array_keys($value))
            : [$group];
        foreach ($keys as $key) {
            if (! str_contains($haystack, "| `{$key}` |")) {
                $missing[] = $key;
            }
        }
    }

    expect($missing)->toBe([]);
});
