<?php

declare(strict_types=1);

use Lucasp\Loom\Mcp\LoomMcpServer;
use Lucasp\Loom\Mcp\Tools\EventsFollowingTool;
use Lucasp\Loom\Mcp\Tools\EventsFromMethodTool;
use Lucasp\Loom\Mcp\Tools\FindOrphansTool;
use Lucasp\Loom\Mcp\Tools\ImpactOfChangeTool;
use Lucasp\Loom\Mcp\Tools\RouteToEventsTool;

/**
 * A worked event→handler→dispatch chain plus a resolved route:
 *
 *   POST /orders  →  OrderController::store  dispatches  OrderPlaced
 *   OrderPlaced   handled by  SendReceipt    dispatches  ReceiptSent
 *   ReceiptSent   handled by  ArchiveReceipt (no dispatches)
 *
 * Also: a Lonely event (orphan) and an Idle listener (handles nothing).
 *
 * @return array<string, mixed>
 */
function chainToolsIndex(): array
{
    $dispatch = static fn (string $target, string $file): array => [
        'target' => $target,
        'kind' => 'event',
        'confidence' => 'high',
        'file' => $file,
        'line' => 7,
    ];

    return [
        'events' => [
            [
                'id' => 'App\\Events\\OrderPlaced',
                'fqcn' => 'App\\Events\\OrderPlaced',
                'kind' => 'class',
                'file' => 'app/Events/OrderPlaced.php',
                'line' => 10,
                'handled_by' => [['listener' => 'App\\Listeners\\SendReceipt', 'method' => 'handle']],
                'dispatched_from' => [['file' => 'app/Http/Controllers/OrderController.php', 'line' => 20, 'method' => 'dispatch']],
            ],
            [
                'id' => 'App\\Events\\ReceiptSent',
                'fqcn' => 'App\\Events\\ReceiptSent',
                'kind' => 'class',
                'file' => 'app/Events/ReceiptSent.php',
                'line' => 10,
                'handled_by' => [['listener' => 'App\\Listeners\\ArchiveReceipt', 'method' => 'handle']],
                'dispatched_from' => [['file' => 'app/Listeners/SendReceipt.php', 'line' => 7, 'method' => 'dispatch']],
            ],
            [
                'id' => 'App\\Events\\Lonely',
                'fqcn' => 'App\\Events\\Lonely',
                'kind' => 'class',
                'file' => 'app/Events/Lonely.php',
                'line' => 3,
                'handled_by' => [],
                'dispatched_from' => [],
            ],
        ],
        'listeners' => [
            [
                'fqcn' => 'App\\Listeners\\SendReceipt',
                'file' => 'app/Listeners/SendReceipt.php',
                'line' => 5,
                'registration' => 'auto_discovered',
                'queued' => false,
                'handles' => [['event' => 'App\\Events\\OrderPlaced', 'method' => 'handle']],
                'dispatches' => [$dispatch('App\\Events\\ReceiptSent', 'app/Listeners/SendReceipt.php')],
            ],
            [
                'fqcn' => 'App\\Listeners\\ArchiveReceipt',
                'file' => 'app/Listeners/ArchiveReceipt.php',
                'line' => 5,
                'registration' => 'auto_discovered',
                'queued' => false,
                'handles' => [['event' => 'App\\Events\\ReceiptSent', 'method' => 'handle']],
                'dispatches' => [],
            ],
            [
                'fqcn' => 'App\\Listeners\\Idle',
                'file' => 'app/Listeners/Idle.php',
                'line' => 5,
                'registration' => 'auto_discovered',
                'queued' => false,
                'handles' => [],
                'dispatches' => [],
            ],
        ],
        'routes' => [
            [
                'method' => 'POST',
                'uri' => 'orders',
                'name' => 'orders.store',
                'controller_fqcn' => 'App\\Http\\Controllers\\OrderController',
                'controller_method' => 'store',
                'middleware' => ['web'],
                'file' => 'routes/web.php',
                'line' => 12,
                'dispatches' => [$dispatch('App\\Events\\OrderPlaced', 'app/Http/Controllers/OrderController.php')],
            ],
            [
                'method' => 'GET',
                'uri' => 'health',
                'name' => null,
                'controller_fqcn' => null,
                'controller_method' => null,
                'middleware' => [],
                'file' => 'routes/web.php',
                'line' => 3,
                'dispatches' => [],
            ],
        ],
    ];
}

beforeEach(function () {
    mcpUseIndex(mcpTempIndex(chainToolsIndex()));
});

// events-following ------------------------------------------------------------

it('follows an event through its handlers and their dispatches', function () {
    LoomMcpServer::tool(EventsFollowingTool::class, ['event_fqcn' => 'App\\Events\\OrderPlaced'])
        ->assertOk()
        ->assertSee('App\\\\Listeners\\\\SendReceipt::handle')
        ->assertSee('App\\\\Events\\\\ReceiptSent')
        ->assertSee('App\\\\Listeners\\\\ArchiveReceipt::handle');
});

it('returns an empty edge set for an event with no handlers', function () {
    LoomMcpServer::tool(EventsFollowingTool::class, ['event_fqcn' => 'App\\Events\\Lonely'])
        ->assertOk()
        ->assertSee('"edges":[]')
        ->assertSee('App\\\\Events\\\\Lonely');
});

// events-from-method ----------------------------------------------------------

it('builds the transitive closure from a controller method', function () {
    LoomMcpServer::tool(EventsFromMethodTool::class, [
        'method_fqcn' => 'App\\Http\\Controllers\\OrderController::store',
    ])
        ->assertOk()
        ->assertSee('App\\\\Events\\\\OrderPlaced')
        ->assertSee('App\\\\Events\\\\ReceiptSent');
});

it('returns no dispatches for an unknown method', function () {
    LoomMcpServer::tool(EventsFromMethodTool::class, ['method_fqcn' => 'App\\Nope::missing'])
        ->assertOk()
        ->assertSee('"dispatches":[]');
});

// route-to-events -------------------------------------------------------------

it('resolves a route to its controller event chain', function () {
    LoomMcpServer::tool(RouteToEventsTool::class, ['method' => 'post', 'uri' => '/orders'])
        ->assertOk()
        ->assertSee('orders.store')
        ->assertSee('App\\\\Http\\\\Controllers\\\\OrderController::store')
        ->assertSee('App\\\\Events\\\\OrderPlaced')
        ->assertSee('App\\\\Events\\\\ReceiptSent');
});

it('notes a closure route has no event chain', function () {
    LoomMcpServer::tool(RouteToEventsTool::class, ['method' => 'GET', 'uri' => 'health'])
        ->assertOk()
        ->assertSee('no resolved controller')
        ->assertSee('"chain":null');
});

it('errors when no route matches', function () {
    LoomMcpServer::tool(RouteToEventsTool::class, ['method' => 'DELETE', 'uri' => 'nope'])
        ->assertSee('No route found');
});

// impact-of-change ------------------------------------------------------------

it('reports the blast radius of removing an event', function () {
    LoomMcpServer::tool(ImpactOfChangeTool::class, ['fqcn' => 'App\\Events\\OrderPlaced'])
        ->assertOk()
        ->assertSee('"entity":"event"')
        ->assertSee('App\\\\Listeners\\\\SendReceipt')
        ->assertSee('app/Http/Controllers/OrderController.php');
});

it('flags an event orphaned by removing its only listener', function () {
    LoomMcpServer::tool(ImpactOfChangeTool::class, ['fqcn' => 'App\\Listeners\\ArchiveReceipt'])
        ->assertOk()
        ->assertSee('"entity":"listener"')
        ->assertSee('App\\\\Events\\\\ReceiptSent');
});

it('notes when an FQCN is unknown to the index', function () {
    LoomMcpServer::tool(ImpactOfChangeTool::class, ['fqcn' => 'App\\Events\\DoesNotExist'])
        ->assertOk()
        ->assertSee('"entity":"unknown"');
});

// find-orphans ----------------------------------------------------------------

it('lists orphan events and idle listeners', function () {
    LoomMcpServer::tool(FindOrphansTool::class)
        ->assertOk()
        ->assertSee('App\\\\Events\\\\Lonely')
        ->assertSee('App\\\\Listeners\\\\Idle');
});

it('returns empty orphan sets for a fully-wired index', function () {
    mcpUseIndex(mcpTempIndex([
        'events' => [[
            'id' => 'App\\Events\\Wired',
            'fqcn' => 'App\\Events\\Wired',
            'kind' => 'class',
            'file' => 'app/Events/Wired.php',
            'line' => 1,
            'handled_by' => [['listener' => 'App\\Listeners\\Wired', 'method' => 'handle']],
            'dispatched_from' => [['file' => 'app/X.php', 'line' => 1, 'method' => 'dispatch']],
        ]],
        'listeners' => [[
            'fqcn' => 'App\\Listeners\\Wired',
            'file' => 'app/Listeners/Wired.php',
            'line' => 1,
            'registration' => 'auto_discovered',
            'queued' => false,
            'handles' => [['event' => 'App\\Events\\Wired', 'method' => 'handle']],
            'dispatches' => [],
        ]],
    ]));

    LoomMcpServer::tool(FindOrphansTool::class)
        ->assertOk()
        ->assertSee('"orphan_events":[]')
        ->assertSee('"idle_listeners":[]');
});
