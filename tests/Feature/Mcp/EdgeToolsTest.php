<?php

declare(strict_types=1);

use Lucasp\Loom\Mcp\LoomMcpServer;
use Lucasp\Loom\Mcp\Tools\DispatchesFromTool;
use Lucasp\Loom\Mcp\Tools\DispatchSitesForTool;
use Lucasp\Loom\Mcp\Tools\HandlersForTool;

// -----------------------------------------------------------------------------
// dispatch-sites-for
// -----------------------------------------------------------------------------

it('reports the sites that dispatch an event', function () {
    mcpUseIndex(mcpTempIndex([
        'events' => [[
            'id' => 'App\\Events\\OrderPlaced',
            'fqcn' => 'App\\Events\\OrderPlaced',
            'kind' => 'class',
            'file' => 'app/Events/OrderPlaced.php',
            'line' => 10,
            'handled_by' => [],
            'dispatched_from' => [
                ['file' => 'app/Services/Checkout.php', 'line' => 42, 'method' => 'finalize'],
            ],
        ]],
    ]));

    LoomMcpServer::tool(DispatchSitesForTool::class, ['event_fqcn' => 'App\\Events\\OrderPlaced'])
        ->assertOk()
        ->assertName('dispatch-sites-for')
        ->assertSee('app/Services/Checkout.php')
        ->assertSee('finalize')
        ->assertSee('"count":1');
});

it('returns an empty dispatch-site list for an unknown event', function () {
    mcpUseIndex(mcpTempIndex());

    LoomMcpServer::tool(DispatchSitesForTool::class, ['event_fqcn' => 'App\\Events\\Nope'])
        ->assertOk()
        ->assertSee('"count":0')
        ->assertSee('"dispatch_sites":[]');
});

// -----------------------------------------------------------------------------
// handlers-for
// -----------------------------------------------------------------------------

it('reports named and closure handlers of an event', function () {
    mcpUseIndex(mcpTempIndex([
        'events' => [[
            'id' => 'App\\Events\\OrderPlaced',
            'fqcn' => 'App\\Events\\OrderPlaced',
            'kind' => 'class',
            'file' => 'app/Events/OrderPlaced.php',
            'line' => 10,
            'handled_by' => [
                ['listener' => 'App\\Listeners\\SendMail', 'method' => 'handle'],
            ],
            'dispatched_from' => [],
        ]],
        'listeners' => [[
            'fqcn' => 'App\\Listeners\\SendMail',
            'file' => 'app/Listeners/SendMail.php',
            'line' => 8,
            'registration' => 'auto_discovered',
            'queued' => true,
            'handles' => [['event' => 'App\\Events\\OrderPlaced', 'method' => 'handle']],
            'dispatches' => [],
        ]],
        'closure_listeners' => [[
            'event' => 'App\\Events\\OrderPlaced',
            'file' => 'routes/events.php',
            'line' => 3,
            'end_line' => 6,
            'registration' => 'event_listen_call',
            'queued' => false,
            'dispatches' => [],
        ]],
    ]));

    LoomMcpServer::tool(HandlersForTool::class, ['event_fqcn' => 'App\\Events\\OrderPlaced'])
        ->assertOk()
        ->assertName('handlers-for')
        ->assertSee('App\\\\Listeners\\\\SendMail')
        ->assertSee('"queued":true')
        ->assertSee('routes/events.php');
});

it('returns empty handler lists for an unknown event', function () {
    mcpUseIndex(mcpTempIndex());

    LoomMcpServer::tool(HandlersForTool::class, ['event_fqcn' => 'App\\Events\\Nope'])
        ->assertOk()
        ->assertSee('"listeners":[]')
        ->assertSee('"closure_listeners":[]');
});

// -----------------------------------------------------------------------------
// dispatches-from
// -----------------------------------------------------------------------------

it('reports what a controller method directly dispatches', function () {
    mcpUseIndex(mcpTempIndex([
        'routes' => [[
            'method' => 'POST',
            'uri' => 'orders',
            'name' => 'orders.store',
            'controller_fqcn' => 'App\\Http\\Controllers\\OrderController',
            'controller_method' => 'store',
            'middleware' => [],
            'file' => 'routes/web.php',
            'line' => 12,
            'dispatches' => [[
                'target' => 'App\\Events\\OrderPlaced',
                'kind' => 'event',
                'confidence' => 'high',
                'file' => 'app/Http/Controllers/OrderController.php',
                'line' => 30,
            ]],
        ]],
    ]));

    LoomMcpServer::tool(DispatchesFromTool::class, ['method_fqcn' => 'App\\Http\\Controllers\\OrderController::store'])
        ->assertOk()
        ->assertName('dispatches-from')
        ->assertSee('App\\\\Events\\\\OrderPlaced')
        ->assertSee('"count":1')
        ->assertSee('"kind":"event"');
});

it('returns no dispatches for an unknown method', function () {
    mcpUseIndex(mcpTempIndex());

    LoomMcpServer::tool(DispatchesFromTool::class, ['method_fqcn' => 'App\\Nope::run'])
        ->assertOk()
        ->assertSee('"count":0')
        ->assertSee('"dispatches":[]');
});
