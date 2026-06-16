<?php

declare(strict_types=1);

use Lucasp\Loom\Mcp\LoomMcpServer;
use Lucasp\Loom\Mcp\Tools\GetEntityTool;
use Lucasp\Loom\Mcp\Tools\ListEntitiesTool;

it('lists a populated section with its items and count', function () {
    mcpUseIndex(mcpTempIndex([
        'events' => [
            ['id' => 'evt-1', 'fqcn' => 'App\\Events\\OrderShipped', 'kind' => 'event', 'file' => 'app/Events/OrderShipped.php', 'line' => 12, 'dispatched_from' => [], 'handled_by' => []],
        ],
    ]));

    LoomMcpServer::tool(ListEntitiesTool::class, ['section' => 'events'])
        ->assertOk()
        ->assertName('list-entities')
        ->assertSee('App\\\\Events\\\\OrderShipped')
        ->assertSee('"count":1');
});

it('lists an empty section with a zero count', function () {
    mcpUseIndex(mcpTempIndex());

    LoomMcpServer::tool(ListEntitiesTool::class, ['section' => 'jobs'])
        ->assertOk()
        ->assertSee('"section":"jobs"')
        ->assertSee('"count":0');
});

it('gets an existing entity by kind and fqcn', function () {
    mcpUseIndex(mcpTempIndex([
        'listeners' => [
            ['fqcn' => 'App\\Listeners\\SendShipmentNotification', 'file' => 'app/Listeners/SendShipmentNotification.php', 'line' => 8, 'handles' => [], 'registration' => 'auto_discovered', 'queued' => false, 'dispatches' => []],
        ],
    ]));

    LoomMcpServer::tool(GetEntityTool::class, [
        'kind' => 'listener',
        'fqcn' => 'App\\Listeners\\SendShipmentNotification',
    ])
        ->assertOk()
        ->assertName('get-entity')
        ->assertSee('"found":true')
        ->assertSee('App\\\\Listeners\\\\SendShipmentNotification');
});

it('errors when the requested entity is missing', function () {
    mcpUseIndex(mcpTempIndex());

    LoomMcpServer::tool(GetEntityTool::class, [
        'kind' => 'event',
        'fqcn' => 'App\\Events\\DoesNotExist',
    ])
        ->assertHasErrors()
        ->assertSee('No event found for App\\Events\\DoesNotExist.');
});
