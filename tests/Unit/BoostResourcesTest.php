<?php

declare(strict_types=1);

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Mcp\Server\Attributes\Name;
use Lucasp\Loom\Mcp\LoomMcpServer;

/**
 * Boost reads these files from vendor/, so tool and input names that drift
 * from the MCP surface would send agents to tools that do not exist.
 *
 * @return array<string, list<string>> tool name => input names
 */
function loomMcpSurface(): array
{
    $surface = [];
    foreach ((new ReflectionProperty(LoomMcpServer::class, 'tools'))->getDefaultValue() as $class) {
        $reflection = new ReflectionClass($class);
        $name = $reflection->getAttributes(Name::class)[0]->newInstance()->value;
        $tool = $reflection->newInstanceWithoutConstructor();
        $surface[$name] = array_keys($tool->schema(new JsonSchemaTypeFactory));
    }

    return $surface;
}

/** @return array<string, string> label => absolute path */
function boostFiles(): array
{
    $root = dirname(__DIR__, 2).'/resources/boost';

    return [
        'guideline' => $root.'/guidelines/core.blade.php',
        'skill' => $root.'/skills/loom/SKILL.md',
    ];
}

it('only names MCP tools and inputs that exist', function (string $label): void {
    $surface = loomMcpSurface();
    $lines = file(boostFiles()[$label], FILE_IGNORE_NEW_LINES);
    $mentioned = 0;

    foreach ($lines as $line) {
        preg_match_all('/`([a-z]+(?:-[a-z]+)+)`/', $line, $tools);
        if ($tools[1] === []) {
            continue;
        }

        $allowed = ['remove', 'rename'];
        foreach ($tools[1] as $tool) {
            expect($surface)->toHaveKey($tool);
            $allowed = array_merge($allowed, $surface[$tool]);
            $mentioned++;
        }

        // Inputs are written in parentheses after a tool, or after "with".
        $scope = '';
        if (preg_match_all('/\(([^)]*)\)|\bwith\b(.*)$/', $line, $m)) {
            $scope = implode(' ', array_merge($m[1], $m[2]));
        }
        preg_match_all('/`([a-z_]+)`/', $scope, $params);
        foreach ($params[1] as $param) {
            expect($allowed)->toContain($param);
        }
    }

    expect($mentioned)->toBeGreaterThan(0);
})->with(['guideline', 'skill']);

it('ships a skill with name and description frontmatter', function (): void {
    $content = (string) file_get_contents(boostFiles()['skill']);

    expect(preg_match('/\A---\R(.*?)\R---\R/s', $content, $m))->toBe(1)
        ->and($m[1])->toContain('name: loom')
        ->and($m[1])->toMatch('/^description: \S/m');
});

it('keeps the guideline short', function (): void {
    expect(count(file(boostFiles()['guideline'])))->toBeLessThan(40);
});
