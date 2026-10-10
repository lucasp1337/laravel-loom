<?php

declare(strict_types=1);

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Mcp\Server\Attributes\Name;
use Lucasp\Loom\Mcp\LoomMcpServer;

/**
 * The MCP tool names and input parameter names are a stable contract (see
 * docs/reference/mcp-tools.md). Changing this list is a breaking change.
 */
it('pins every MCP tool name and input parameter name', function (): void {
    $actual = [];
    foreach ((new ReflectionProperty(LoomMcpServer::class, 'tools'))->getDefaultValue() as $class) {
        $reflection = new ReflectionClass($class);
        $name = $reflection->getAttributes(Name::class)[0]->newInstance()->value;
        $tool = $reflection->newInstanceWithoutConstructor();
        $actual[$name] = array_keys($tool->schema(new JsonSchemaTypeFactory));
    }

    expect($actual)->toBe([
        'list-entities' => ['section'],
        'get-entity' => ['kind', 'fqcn'],
        'dispatch-sites-for' => ['event_fqcn'],
        'handlers-for' => ['event_fqcn'],
        'dispatches-from' => ['method_fqcn'],
        'events-following' => ['event_fqcn', 'depth'],
        'events-from-method' => ['method_fqcn', 'depth'],
        'route-to-events' => ['method', 'uri', 'depth'],
        'impact-of-change' => ['fqcn', 'change'],
        'find-orphans' => [],
        'find-unresolved-dispatches' => [],
    ]);
});
