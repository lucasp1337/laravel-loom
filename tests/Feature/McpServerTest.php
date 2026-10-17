<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Laravel\Mcp\Server\Registrar;
use Lucasp\Loom\Mcp\LoomMcpServer;
use Lucasp\Loom\Mcp\Tools\FindUnresolvedDispatchesTool;

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
    $server = app(Registrar::class)->getLocalServer('loom');
    expect($server)->not->toBeNull();

    expect(array_keys(app(Kernel::class)->all()))
        ->toContain('loom:mcp');
});
