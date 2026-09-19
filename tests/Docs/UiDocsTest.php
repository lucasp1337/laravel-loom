<?php

declare(strict_types=1);

use Lucasp\Loom\Query\ChainDepth;
use Lucasp\Loom\Ui\SectionPresentation;

/**
 * Drift guard for the browser UI pages: config keys, shortcuts and leaks.
 */
function uiDoc(string $relative): string
{
    $path = dirname(__DIR__, 2).'/'.$relative;
    expect(is_file($path))->toBeTrue("{$relative} is missing");

    return (string) file_get_contents($path);
}

it('documents every ui config key with its env variable', function (): void {
    $doc = uiDoc('docs/reference/ui-config.md');
    $config = require dirname(__DIR__, 2).'/config/loom.php';

    $keys = ['index_path', ...array_map(static fn (int|string $k): string => "ui.{$k}", array_keys($config['ui']))];
    $missing = array_values(array_filter($keys, static fn (int|string $k): bool => ! str_contains($doc, "`{$k}`")));

    $source = (string) file_get_contents(dirname(__DIR__, 2).'/config/loom.php');
    preg_match_all("/env\('([A-Z_]+)'/", $source, $envs);
    foreach ($envs[1] as $env) {
        if (! str_contains($doc, "`{$env}`")) {
            $missing[] = $env;
        }
    }

    expect($missing)->toBe([]);
});

it('states the chain depth bounds and default from the code', function (): void {
    $doc = uiDoc('docs/reference/ui-config.md');
    $config = require dirname(__DIR__, 2).'/config/loom.php';

    expect($doc)->toContain('| `ui.chain_depth` | none | `'.$config['ui']['chain_depth'].'`')
        ->and($doc)->toContain('1-'.ChainDepth::MAX);
});

it('documents only shortcuts that exist and every section shortcut', function (): void {
    $guide = uiDoc('docs/guides/browse-the-ui.md');
    $js = (string) file_get_contents(dirname(__DIR__, 2).'/resources/dist/loom.js');

    // Keys the script handles itself.
    expect($js)->toContain("'k'")->toContain("'Escape'")->toContain("'/'")->toContain("'g'");

    $shortcuts = ['d' => true];
    foreach (SectionPresentation::all() as $spec) {
        if ($spec->shortcut !== null) {
            $shortcuts[$spec->shortcut] = true;
        }
    }

    preg_match_all('/`g` then `(\w)`/', $guide, $m);
    $documented = array_unique($m[1]);

    expect(array_values(array_diff($documented, array_keys($shortcuts))))->toBe([])
        ->and(array_values(array_diff(array_keys($shortcuts), $documented)))->toBe([]);
});

it('keeps internals out of the ui pages', function (): void {
    $leaks = [
        '/#\d+/' => 'issue reference',
        '/\bADR\b/' => 'ADR reference',
        '#src/#' => 'source path',
        '#resources/(dist|views)#' => 'package path',
        '/phase \d/i' => 'phase reference',
        '/AGENTS\.md/' => 'AGENTS.md',
        '/Livewire|Alpine|Lucasp\\\\Loom|ChainGraph|ChainWalker|SectionPresentation|UiContext|LoomConfig/' => 'internal name',
    ];

    $found = [];
    foreach (['docs/guides/browse-the-ui.md', 'docs/reference/ui-config.md'] as $page) {
        $prose = (string) preg_replace('/```.*?```/s', '', uiDoc($page));
        foreach ($leaks as $pattern => $label) {
            if (preg_match($pattern, $prose, $m)) {
                $found[] = "{$page}: {$label} ({$m[0]})";
            }
        }
    }

    expect($found)->toBe([]);
});
