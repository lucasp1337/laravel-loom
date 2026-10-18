<?php

declare(strict_types=1);

use Laravel\Mcp\Server\Attributes\Name;
use Lucasp\Loom\Index\DispatchMode;
use Lucasp\Loom\Mcp\LoomMcpServer;
use Lucasp\Loom\Mcp\Tools\EventsFollowingTool;
use Lucasp\Loom\Mcp\Tools\EventsFromMethodTool;
use Lucasp\Loom\Mcp\Tools\ImpactOfChangeTool;
use Lucasp\Loom\Mcp\Tools\RouteToEventsTool;
use Lucasp\Loom\Scanners\Dispatch\DispatchRules;
use Lucasp\Loom\Support\RoutePathProblem;
use Lucasp\Loom\Tools\Pages\DocsFixture;
use Lucasp\Loom\Tools\Pages\Markdown;
use Lucasp\Loom\Tools\Reference\McpToolDocs;
use Lucasp\Loom\Tools\Reference\SchemaSection;
use Lucasp\Loom\Tools\Reference\ToolDoc;
use Lucasp\Loom\Tools\ReferencePages;

/**
 * The schema, MCP tool and config reference pages are generated from the JSON
 * schema, the tool classes and config/loom.php. Fails when a committed page
 * differs from a fresh render; fix it with `just docs-generate`.
 */
it('keeps the generated reference regions in sync with their sources', function (): void {
    $root = dirname(__DIR__, 2);
    $stale = [];

    foreach ((new ReferencePages($root))->render() as $page => $expected) {
        if ($expected !== file_get_contents($root.'/'.$page)) {
            $stale[] = $page;
        }
    }

    expect($stale)->toBe([], 'Run `just docs-generate` and commit the result.');
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

/**
 * Structural checks on what a reader sees: links land, tables have no holes
 * or constant columns, and the hand-written layer cannot rot silently.
 */
function referenceRoot(): string
{
    return dirname(__DIR__, 2);
}

/** @return list<string> */
function referencePageNames(): array
{
    return [
        'docs/reference/schema.md',
        'docs/reference/mcp-tools.md',
        'docs/reference/scan-config.md',
        'docs/reference/ui-config.md',
        'docs/reference/commands.md',
        'docs/reference/php-api.md',
    ];
}

/** Markdown with fenced code removed, so examples cannot pose as prose. */
function referenceProse(string $markdown): string
{
    return (string) preg_replace('/^```.*?^```/ms', '', $markdown);
}

/** @return list<string> heading slugs of a page, de-duplicated the way the toc extension does */
function referenceSlugs(string $markdown): array
{
    $slugs = [];
    foreach (explode("\n", referenceProse($markdown)) as $line) {
        if (preg_match('/^#{1,6}\s+(.+?)\s*#*$/', $line, $m) !== 1) {
            continue;
        }
        $slug = Markdown::slug($m[1]);
        $unique = $slug;
        for ($i = 1; in_array($unique, $slugs, true); $i++) {
            $unique = "{$slug}_{$i}";
        }
        $slugs[] = $unique;
    }

    return $slugs;
}

it('computes heading slugs like the site builder', function (): void {
    expect(Markdown::slug('`events[]`'))->toBe('events')
        ->and(Markdown::slug('`scheduled_tasks[].frequency`'))->toBe('scheduled_tasksfrequency')
        ->and(Markdown::slug('Call forms by `mode`'))->toBe('call-forms-by-mode')
        ->and(Markdown::slug('Why was my code missed?'))->toBe('why-was-my-code-missed');
});

it('resolves every link on the generated reference pages', function (): void {
    $pages = (new ReferencePages(referenceRoot()))->render();
    $broken = [];

    foreach (referencePageNames() as $page) {
        $prose = referenceProse($pages[$page]);
        preg_match_all('/\]\(([^)\s]+)\)/', $prose, $links);
        foreach ($links[1] as $link) {
            if (preg_match('#^[a-z]+://#', $link) === 1) {
                continue;
            }
            [$target, $anchor] = array_pad(explode('#', $link, 2), 2, null);
            $file = $target === '' ? $page : (string) preg_replace('#[^/]+/\.\./#', '', dirname($page).'/'.$target);
            $absolute = referenceRoot().'/'.$file;
            if (! is_file($absolute)) {
                $broken[] = "{$page}: {$link} (no such file)";

                continue;
            }
            $content = $pages[$file] ?? (string) file_get_contents($absolute);
            if ($anchor !== null && ! in_array($anchor, referenceSlugs($content), true)) {
                $broken[] = "{$page}: {$link} (no such heading)";
            }
        }
    }

    expect($broken)->toBe([]);
});

it('has no empty cells and no constant columns in the reference tables', function (): void {
    $pages = (new ReferencePages(referenceRoot()))->render();
    $problems = [];

    foreach (referencePageNames() as $page) {
        $rows = [];
        $flush = static function () use (&$rows, &$problems, $page): void {
            $body = array_slice($rows, 2);
            foreach ($body as $row) {
                foreach ($row as $i => $cell) {
                    if ($cell === '') {
                        $problems[] = "{$page}: empty cell in column {$rows[0][$i]}";
                    }
                }
            }
            foreach ($rows[0] ?? [] as $i => $header) {
                $column = array_unique(array_column($body, $i));
                if (count($body) >= 3 && count($column) === 1 && in_array($column[0], ['none', 'yes', 'no'], true)) {
                    $problems[] = "{$page}: column {$header} is always {$column[0]}";
                }
            }
            $rows = [];
        };

        foreach (explode("\n", referenceProse($pages[$page]).' ') as $line) {
            if (str_starts_with($line, '|')) {
                $rows[] = array_map('trim', explode('|', trim($line, '| ')));

                continue;
            }
            if (count($rows) >= 2) {
                $flush();
            }
            $rows = [];
        }
    }

    expect($problems)->toBe([]);
});

it('gives every schema section and MCP tool a hand-written description', function (): void {
    $root = referenceRoot();
    $schema = json_decode((string) file_get_contents($root.'/schema/loom-index.schema.json'), true);
    $sections = array_keys(array_filter($schema['properties'], static fn (array $p): bool => isset($p['items']['$ref'])));

    expect(array_map(static fn (SchemaSection $s): string => $s->value, SchemaSection::cases()))->toEqualCanonicalizing($sections);

    $registered = (new ReflectionProperty(LoomMcpServer::class, 'tools'))->getDefaultValue();
    $documented = array_map(static fn (ToolDoc $doc): string => $doc->tool, McpToolDocs::all());

    expect($documented)->toEqualCanonicalizing($registered);
});

it('only mentions keys in MCP notes that a real response, input or schema field carries', function (): void {
    $root = referenceRoot();
    $fixture = new DocsFixture($root);

    $known = [];
    $collect = static function (mixed $value) use (&$collect, &$known): void {
        if (is_string($value)) {
            $known[$value] = true;
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $known[(string) $key] = true;
                $collect($item);
            }
        }
    };

    $schema = json_decode((string) file_get_contents($root.'/schema/loom-index.schema.json'), true);
    $walk = static function (array $node) use (&$walk, &$known): void {
        foreach ($node as $key => $value) {
            if ($key === 'properties' && is_array($value)) {
                foreach (array_keys($value) as $property) {
                    $known[(string) $property] = true;
                }
            }
            if (is_array($value)) {
                $walk($value);
            }
        }
    };
    $walk($schema);

    $unknown = [];
    foreach (McpToolDocs::all() as $doc) {
        $name = (new ReflectionClass($doc->tool))->getAttributes(Name::class)[0]->newInstance()->value;
        $calls = [$doc->example, ...array_map(static fn ($variant): array => $variant->args, $doc->variants)];
        foreach ($calls as $args) {
            $collect(json_decode($fixture->call($doc->tool, $args)['text'], true));
            $collect($args);
        }
        foreach (array_keys($fixture->inputs($doc->tool)) as $input) {
            $known[$input] = true;
        }
        preg_match_all('/`([a-z][a-z0-9_]*)`/', implode(' ', $doc->notes), $tokens);
        foreach ($tokens[1] as $token) {
            if (! isset($known[$token]) && ! in_array($token, ['true', 'false', 'null'], true)) {
                $unknown[] = "{$name}: {$token}";
            }
        }
    }

    expect($unknown)->toBe([]);
});

it('keeps the shared chain keys in every chain-shaped response', function (): void {
    $fixture = new DocsFixture(referenceRoot());
    $chainKeys = ['truncated', 'cycles', 'events_reached'];
    $order = 'App\\Events\\OrderPlaced';

    $following = json_decode($fixture->call(EventsFollowingTool::class, ['event_fqcn' => $order])['text'], true);
    $fromMethod = json_decode($fixture->call(EventsFromMethodTool::class, ['method_fqcn' => 'App\\Http\\Controllers\\OrderController::store'])['text'], true);
    $route = json_decode($fixture->call(RouteToEventsTool::class, ['method' => 'POST', 'uri' => '/orders'])['text'], true);
    $impact = json_decode($fixture->call(ImpactOfChangeTool::class, ['fqcn' => $order])['text'], true);

    expect($chainKeys)->each->toBeIn(array_keys($following))
        ->and($chainKeys)->each->toBeIn(array_keys($fromMethod['chains'][0]))
        ->and($chainKeys)->each->toBeIn(array_keys($route['chain']['chains'][0]))
        ->and($chainKeys)->each->toBeIn(array_keys($impact['downstream']));
});

it('keeps every fenced json block on the MCP page valid', function (): void {
    $page = (string) file_get_contents(referenceRoot().'/docs/reference/mcp-tools.md');
    preg_match_all('/^```json\n(.*?)^```/ms', $page, $blocks);

    expect($blocks[1])->not->toBeEmpty();
    foreach ($blocks[1] as $block) {
        json_decode($block, true, 512, JSON_THROW_ON_ERROR);
    }
});

it('lists dispatch call forms by mode the way the rule table assigns them', function (): void {
    $page = (string) file_get_contents(referenceRoot().'/docs/reference/schema.md');
    preg_match('/^#### Call forms by `mode`\n(.*?)\n\n/ms', $page, $section);
    preg_match_all('/^\| `?(\w+)`? \| (.+) \|$/m', $section[1], $rows, PREG_SET_ORDER);

    $tableNames = [];
    foreach ($rows as [, $mode, $cell]) {
        $key = $mode === 'omitted' ? 'omitted' : $mode;
        preg_match_all('/`([^`]+)`/', $cell, $spans);
        foreach ($spans[1] as $span) {
            $span = (string) preg_replace('/\(\)$/', '', $span);
            $name = (string) preg_replace('/^.*(::|->)/', '', $span);
            foreach (explode('/', $name) as $part) {
                $tableNames[$key][] = trim($part, '()');
            }
        }
    }

    $ruleModes = [];
    foreach (DispatchRules::all() as $rule) {
        $ruleModes[$rule->name][] = $rule->mode?->value ?? 'omitted';
    }

    $wrong = [];
    foreach ($tableNames as $mode => $names) {
        foreach ($names as $name) {
            // `->afterResponse()` is a chain modifier, not a dispatch rule
            if ($name === 'afterResponse' && $mode === 'after_response') {
                continue;
            }
            if (! in_array($mode, $ruleModes[$name] ?? [], true)) {
                $wrong[] = "{$name} is not a {$mode} form";
            }
        }
    }
    foreach (DispatchRules::all() as $rule) {
        $mode = $rule->mode?->value;
        if ($mode !== null && ! in_array($rule->name, $tableNames[$mode] ?? [], true)) {
            $wrong[] = "{$rule->name} ({$mode}) is missing from the table";
        }
    }

    expect($wrong)->toBe([])
        ->and(array_keys($tableNames))->toContain(...array_map(static fn (DispatchMode $m): string => $m->value, DispatchMode::cases()));
});

it('documents every route-path reason the scan command can print', function (): void {
    $page = (string) file_get_contents(referenceRoot().'/docs/reference/scan-config.md');

    $missing = [];
    foreach (RoutePathProblem::cases() as $problem) {
        if (! str_contains($page, '`'.$problem->message().'`')) {
            $missing[] = $problem->message();
        }
    }

    expect($missing)->toBe([]);
});

it('renders no <br> on any generated page', function (): void {
    $pages = array_keys(array_filter(
        (new ReferencePages(referenceRoot()))->render(),
        static fn (string $content): bool => str_contains(referenceProse($content), '<br>'),
    ));

    expect($pages)->toBe([]);
});

it('keeps every schema field description to one sentence', function (): void {
    $schema = json_decode((string) file_get_contents(referenceRoot().'/schema/loom-index.schema.json'), true);
    $long = [];

    $walk = static function (array $node, string $path) use (&$walk, &$long): void {
        foreach ($node['properties'] ?? [] as $field => $property) {
            // Outside code spans, a line break or a period followed by more text starts a second sentence.
            $text = (string) preg_replace('/`[^`]*`/', 'x', (string) ($property['description'] ?? ''));
            if (preg_match('/\R|[.!?]\s+\S/', $text) === 1) {
                $long[] = "{$path}.{$field}";
            }
        }
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $walk($value, is_string($key) && $key !== 'properties' ? "{$path}/{$key}" : $path);
            }
        }
    };
    $walk($schema, '');

    expect($long)->toBe([]);
});
