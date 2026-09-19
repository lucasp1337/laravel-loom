<?php

declare(strict_types=1);

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Mcp\Server\Attributes\Name;
use Lucasp\Loom\Console\McpCommand;
use Lucasp\Loom\Mcp\LoomMcpServer;

/**
 * Drift guard for the MCP consumer docs: the reference page must list every
 * registered tool and input, and the guide must mention every command flag.
 */
function mcpDocPath(string $relative): string
{
    return dirname(__DIR__, 2).'/'.$relative;
}

function mcpDocText(string $relative): string
{
    $path = mcpDocPath($relative);
    expect(is_file($path))->toBeTrue("{$relative} is missing");

    return (string) file_get_contents($path);
}

/**
 * @return array<string, list<string>> registered tool name => input keys
 */
function registeredMcpTools(): array
{
    $classes = (new ReflectionProperty(LoomMcpServer::class, 'tools'))->getDefaultValue();
    $tools = [];
    foreach ($classes as $class) {
        $reflection = new ReflectionClass($class);
        $attributes = $reflection->getAttributes(Name::class);
        $name = $attributes[0]->newInstance()->value;
        $tool = $reflection->newInstanceWithoutConstructor();
        $tools[$name] = array_keys($tool->schema(new JsonSchemaTypeFactory));
    }

    return $tools;
}

it('documents every registered MCP tool in mcp-tools.md', function (): void {
    $doc = mcpDocText('docs/reference/mcp-tools.md');
    $tools = registeredMcpTools();
    expect($tools)->not->toBeEmpty();

    $missing = array_values(array_filter(
        array_keys($tools),
        static fn (string $name): bool => ! str_contains($doc, "`{$name}`"),
    ));

    expect($missing)->toBe([]);
});

it('documents every MCP tool input in its mcp-tools.md section', function (): void {
    $doc = mcpDocText('docs/reference/mcp-tools.md');

    $missing = [];
    foreach (registeredMcpTools() as $name => $inputs) {
        $start = strpos($doc, "### `{$name}`");
        if ($start === false) {
            $missing[] = "{$name}: no section";

            continue;
        }
        $next = strpos($doc, "\n#", $start + 1);
        $section = substr($doc, $start, $next === false ? null : $next - $start);
        foreach ($inputs as $input) {
            if (! str_contains($section, "`{$input}`")) {
                $missing[] = "{$name}: {$input}";
            }
        }
    }

    expect($missing)->toBe([]);
});

it('documents every loom:mcp flag in ask-an-agent.md', function (): void {
    $doc = mcpDocText('docs/guides/ask-an-agent.md');
    $source = (string) file_get_contents((string) (new ReflectionClass(McpCommand::class))->getFileName());
    preg_match_all('/\{--([a-z][a-z-]*)/', $source, $flags);
    expect($flags[1])->not->toBeEmpty();

    $missing = array_values(array_filter(
        $flags[1],
        static fn (string $flag): bool => ! str_contains($doc, "`--{$flag}"),
    ));

    expect($missing)->toBe([]);
});

it('keeps internal references out of the MCP pages', function (): void {
    $leaks = [
        '/#\d+/' => 'issue reference',
        '/\bADR\b/' => 'ADR reference',
        '#src/#' => 'source path',
        '/phase \d/i' => 'phase reference',
        '/schema-guardian/' => 'agent name',
        '/AGENTS\.md/' => 'AGENTS.md',
        '/laravel-atlas/' => 'repository name',
        '/tests\/Fixtures/' => 'fixture path',
    ];

    $found = [];
    foreach (['docs/guides/ask-an-agent.md', 'docs/reference/mcp-tools.md'] as $relative) {
        $prose = (string) preg_replace('/```.*?```/s', '', mcpDocText($relative));
        // Heading anchors such as (#keep-stdout-clean) are links, not issue numbers.
        $prose = (string) preg_replace('/\]\([^)]*\)/', ']()', $prose);
        foreach ($leaks as $pattern => $label) {
            if (preg_match($pattern, $prose, $m)) {
                $found[] = "{$relative}: {$label} ({$m[0]})";
            }
        }
    }

    expect($found)->toBe([]);
});
