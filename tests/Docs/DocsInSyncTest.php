<?php

declare(strict_types=1);

use JsonSchema\Validator;
use Lucasp\Loom\Check\RuleKey;

/**
 * Drift guard: fails when the consumer docs stop matching the code.
 * A doc that does not exist yet is skipped, not failed.
 */
function docsRoot(): string
{
    return dirname(__DIR__, 2);
}

function docContents(string $relative): ?string
{
    $path = docsRoot().'/'.$relative;

    return is_file($path) ? (string) file_get_contents($path) : null;
}

/**
 * @return array<int, array{name: string, options: list<string>, arguments: list<string>}>
 */
function declaredCommands(): array
{
    $commands = [];
    foreach (glob(docsRoot().'/src/Console/*Command.php') ?: [] as $file) {
        $src = (string) file_get_contents($file);
        if (! preg_match("/\\\$signature\s*=\s*'([^']+)'/s", $src, $m)) {
            continue;
        }
        $sig = $m[1];
        if (! preg_match('/^\s*(\S+)/', $sig, $n)) {
            continue;
        }
        preg_match_all('/\{--([a-z][a-z-]*)/', $sig, $opts);
        preg_match_all('/\{([a-z][a-z-]*)[?*:=}\s]/', $sig, $args);
        $commands[] = ['name' => $n[1], 'options' => $opts[1], 'arguments' => $args[1]];
    }

    return $commands;
}

it('documents every command, option and argument in commands.md', function (): void {
    $doc = docContents('docs/reference/commands.md');
    if ($doc === null) {
        $this->markTestSkipped('docs/reference/commands.md not written yet');
    }

    $commands = declaredCommands();
    expect($commands)->not->toBeEmpty();

    $missing = [];
    foreach ($commands as $cmd) {
        if (! str_contains($doc, $cmd['name'])) {
            $missing[] = $cmd['name'];
        }
        foreach ($cmd['options'] as $opt) {
            if (! str_contains($doc, "--{$opt}")) {
                $missing[] = "{$cmd['name']} --{$opt}";
            }
        }
        foreach ($cmd['arguments'] as $arg) {
            if (! str_contains($doc, "`{$arg}`")) {
                $missing[] = "{$cmd['name']} argument {$arg}";
            }
        }
    }

    expect($missing)->toBe([]);
});

it('documents every check rule key', function (): void {
    $doc = docContents('docs/reference/check-rules-and-formats.md');
    if ($doc === null) {
        $this->markTestSkipped('docs/reference/check-rules-and-formats.md not written yet');
    }

    $missing = array_values(array_filter(
        array_map(static fn (RuleKey $k): string => $k->value, RuleKey::cases()),
        static fn (string $key): bool => ! str_contains($doc, $key),
    ));

    expect($missing)->toBe([]);
});

it('documents every config key', function (): void {
    $docs = [docContents('docs/reference/commands.md')];
    foreach (['docs/guides/browse-the-ui.md', 'docs/reference/ui-config.md'] as $ui) {
        $docs[] = docContents($ui);
    }
    $haystack = implode("\n", array_filter($docs));
    if ($haystack === '') {
        $this->markTestSkipped('no config docs written yet');
    }

    $flatten = static function (array $config, string $prefix = '') use (&$flatten): array {
        $keys = [];
        foreach ($config as $key => $value) {
            $full = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
            is_array($value) && ! array_is_list($value) ? $keys = [...$keys, ...$flatten($value, $full)] : $keys[] = $full;
        }

        return $keys;
    };

    $config = require docsRoot().'/config/loom.php';
    $missing = [];
    foreach ($flatten($config) as $dotted) {
        $leaf = substr($dotted, (int) strrpos($dotted, '.') + 1);
        if (! str_contains($haystack, "`{$leaf}`") && ! str_contains($haystack, "`{$dotted}`")) {
            $missing[] = $dotted;
        }
    }

    expect($missing)->toBe([]);
});

it('mentions every top-level schema section in schema.md', function (): void {
    $doc = docContents('docs/reference/schema.md');
    if ($doc === null) {
        $this->markTestSkipped('docs/reference/schema.md not written yet');
    }

    $schema = json_decode((string) file_get_contents(docsRoot().'/schema/loom-index.schema.json'), true);
    $sections = array_filter(array_keys($schema['properties']), is_string(...));
    $missing = array_values(array_filter(
        $sections,
        static fn (string $section): bool => ! str_contains($doc, $section),
    ));

    expect($missing)->toBe([]);
});

it('keeps full-index JSON examples valid against the schema', function (): void {
    $files = array_merge(
        [docsRoot().'/docs/getting-started.md'],
        glob(docsRoot().'/docs/concepts/*.md') ?: [],
    );
    $files = array_filter($files, 'is_file');
    if ($files === []) {
        $this->markTestSkipped('no getting-started or concept pages yet');
    }

    $schemaPath = docsRoot().'/schema/loom-index.schema.json';
    $invalid = [];
    foreach ($files as $file) {
        preg_match_all('/```json\R(.*?)```/s', (string) file_get_contents($file), $blocks);
        foreach ($blocks[1] as $i => $block) {
            $data = json_decode($block);
            // Blocks that elide content with "..." are illustrations, not full indexes.
            if (! is_object($data) || ! property_exists($data, 'loom_version') || str_contains($block, '"..."')) {
                continue;
            }
            $validator = new Validator;
            $validator->validate($data, (object) ['$ref' => 'file://'.$schemaPath]);
            if (! $validator->isValid()) {
                $errors = array_map(static fn (array $e): string => "{$e['property']}: {$e['message']}", $validator->getErrors());
                $invalid[] = basename($file)." block {$i}: ".implode('; ', array_slice($errors, 0, 3));
            }
        }
    }

    expect($invalid)->toBe([]);
});

it('keeps internal references out of consumer docs', function (): void {
    $leaks = [
        '/#\d+/' => 'issue reference',
        '/\bADR\b/' => 'ADR reference',
        '#src/#' => 'source path',
        '/phase \d/i' => 'phase reference',
        '/schema-guardian/' => 'agent name',
        '/AGENTS\.md/' => 'AGENTS.md',
    ];

    $found = [];
    $root = docsRoot().'/docs';
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        $path = $file->getPathname();
        $relative = substr($path, strlen($root) + 1);
        if ($file->getExtension() !== 'md' || str_starts_with($relative, 'contributing/')) {
            continue;
        }
        // Fenced code (samples, shell) is exempt; prose is not.
        $prose = (string) preg_replace('/```.*?```/s', '', (string) file_get_contents($path));
        foreach ($leaks as $pattern => $label) {
            if (preg_match($pattern, $prose, $m)) {
                $found[] = "docs/{$relative}: {$label} ({$m[0]})";
            }
        }
    }

    expect($found)->toBe([]);
});
