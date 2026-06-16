<?php

declare(strict_types=1);

use Lucasp\Loom\Mcp\IndexRepository;
use Lucasp\Loom\Mcp\LoomMcpServer;
use Lucasp\Loom\Mcp\Tools\FindUnresolvedDispatchesTool;

function mcpTempIndex(array $overrides = []): string
{
    $index = array_merge([
        'loom_version' => '0.3.0',
        'scanned_at' => '2026-01-01T00:00:00+00:00',
        'laravel_version' => '12.x',
        'events' => [],
        'listeners' => [],
        'closure_listeners' => [],
        'observers' => [],
        'model_events' => [],
        'jobs' => [],
        'mailables' => [],
        'notifications' => [],
        'scheduled' => [],
        'routes' => [],
        'unresolved_dispatches' => [],
        'stats' => [],
    ], $overrides);

    $path = tempnam(sys_get_temp_dir(), 'loom-mcp-').'.json';
    file_put_contents($path, json_encode($index));

    return $path;
}

function mcpUseIndex(string $path): void
{
    app(IndexRepository::class)->useSnapshot($path);
}

it('serves unresolved dispatches over the MCP tool surface', function () {
    mcpUseIndex(mcpTempIndex([
        'unresolved_dispatches' => [
            ['file' => 'app/A.php', 'line' => 10, 'expression' => 'dispatch($job)', 'reason' => 'dynamic_class_name'],
        ],
    ]));

    LoomMcpServer::tool(FindUnresolvedDispatchesTool::class)
        ->assertOk()
        ->assertSee('dispatch($job)')
        ->assertSee('dynamic_class_name');
});

it('lists the tool with its description', function () {
    mcpUseIndex(mcpTempIndex());

    LoomMcpServer::tool(FindUnresolvedDispatchesTool::class)
        ->assertOk()
        ->assertName('find-unresolved-dispatches');
});

it('registers the loom local server and the loom:mcp command', function () {
    $server = app(\Laravel\Mcp\Server\Registrar::class)->getLocalServer('loom');
    expect($server)->not->toBeNull();

    expect(array_keys(app(\Illuminate\Contracts\Console\Kernel::class)->all()))
        ->toContain('loom:mcp');
});
