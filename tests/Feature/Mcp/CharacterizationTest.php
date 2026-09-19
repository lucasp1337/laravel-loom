<?php

declare(strict_types=1);

use Laravel\Mcp\Server\Tool;
use Lucasp\Loom\Mcp\LoomMcpServer;
use Lucasp\Loom\Mcp\Tools\DispatchesFromTool;
use Lucasp\Loom\Mcp\Tools\DispatchSitesForTool;
use Lucasp\Loom\Mcp\Tools\EventsFollowingTool;
use Lucasp\Loom\Mcp\Tools\EventsFromMethodTool;
use Lucasp\Loom\Mcp\Tools\FindOrphansTool;
use Lucasp\Loom\Mcp\Tools\FindUnresolvedDispatchesTool;
use Lucasp\Loom\Mcp\Tools\GetEntityTool;
use Lucasp\Loom\Mcp\Tools\HandlersForTool;
use Lucasp\Loom\Mcp\Tools\ImpactOfChangeTool;
use Lucasp\Loom\Mcp\Tools\ListEntitiesTool;
use Lucasp\Loom\Mcp\Tools\RouteToEventsTool;

/**
 * Pins the exact MCP tool surface (names, descriptions, input schemas) and the
 * exact text each tool returns for a fixed index, including JSON key order and
 * error strings. Goldens live in tests/Fixtures/mcp-golden.json; regenerate
 * deliberately with LOOM_UPDATE_GOLDEN=1.
 */
const MCP_TOOLS = [
    ListEntitiesTool::class,
    GetEntityTool::class,
    DispatchSitesForTool::class,
    HandlersForTool::class,
    DispatchesFromTool::class,
    EventsFollowingTool::class,
    EventsFromMethodTool::class,
    RouteToEventsTool::class,
    ImpactOfChangeTool::class,
    FindOrphansTool::class,
    FindUnresolvedDispatchesTool::class,
];

/** @return array<string, array{class-string<Tool>, array<string, mixed>}> */
function mcpCharacterizationCases(): array
{
    $order = 'App\\Events\\OrderPlaced';
    $ctrl = 'App\\Http\\Controllers\\OrderController';

    return [
        'following.default' => [EventsFollowingTool::class, ['event_fqcn' => $order]],
        'following.depth1' => [EventsFollowingTool::class, ['event_fqcn' => $order, 'depth' => 1]],
        'following.depth0_clamped' => [EventsFollowingTool::class, ['event_fqcn' => $order, 'depth' => 0]],
        'following.depth99_clamped' => [EventsFollowingTool::class, ['event_fqcn' => $order, 'depth' => 99]],
        'following.cycle' => [EventsFollowingTool::class, ['event_fqcn' => 'App\\Events\\Ping', 'depth' => 6]],
        'following.closure_and_listener' => [EventsFollowingTool::class, ['event_fqcn' => 'App\\Events\\ReceiptSent']],
        'following.unknown' => [EventsFollowingTool::class, ['event_fqcn' => 'App\\Nope']],
        'following.missing_arg' => [EventsFollowingTool::class, []],
        'from_method.colons' => [EventsFromMethodTool::class, ['method_fqcn' => "{$ctrl}::store"]],
        'from_method.at' => [EventsFromMethodTool::class, ['method_fqcn' => "{$ctrl}@store", 'depth' => 2]],
        'from_method.bare_class' => [EventsFromMethodTool::class, ['method_fqcn' => $ctrl]],
        'from_method.listener_class' => [EventsFromMethodTool::class, ['method_fqcn' => 'App\\Listeners\\PingListener::handle']],
        'from_method.unknown' => [EventsFromMethodTool::class, ['method_fqcn' => 'App\\Nope::missing']],
        'from_method.missing_arg' => [EventsFromMethodTool::class, []],
        'dispatches_from.route_dedupe' => [DispatchesFromTool::class, ['method_fqcn' => "{$ctrl}::store"]],
        'dispatches_from.other_method' => [DispatchesFromTool::class, ['method_fqcn' => "{$ctrl}::update"]],
        'dispatches_from.observer' => [DispatchesFromTool::class, ['method_fqcn' => 'App\\Observers\\OrderObserver']],
        'dispatches_from.job' => [DispatchesFromTool::class, ['method_fqcn' => 'App\\Jobs\\SendMail@handle']],
        'dispatches_from.empty' => [DispatchesFromTool::class, ['method_fqcn' => '']],
        'sites.found' => [DispatchSitesForTool::class, ['event_fqcn' => $order]],
        'sites.none' => [DispatchSitesForTool::class, ['event_fqcn' => 'App\\Events\\Lonely']],
        'sites.empty' => [DispatchSitesForTool::class, ['event_fqcn' => '']],
        'handlers.listener' => [HandlersForTool::class, ['event_fqcn' => $order]],
        'handlers.queued_listener' => [HandlersForTool::class, ['event_fqcn' => 'App\\Events\\Ping']],
        'handlers.with_closure' => [HandlersForTool::class, ['event_fqcn' => 'App\\Events\\ReceiptSent']],
        'handlers.none' => [HandlersForTool::class, ['event_fqcn' => 'App\\Events\\Lonely']],
        'handlers.empty' => [HandlersForTool::class, ['event_fqcn' => '']],
        'route.post' => [RouteToEventsTool::class, ['method' => 'post', 'uri' => '/orders']],
        'route.depth1' => [RouteToEventsTool::class, ['method' => 'POST', 'uri' => 'orders', 'depth' => 1]],
        'route.closure' => [RouteToEventsTool::class, ['method' => 'GET', 'uri' => 'health']],
        'route.invoke' => [RouteToEventsTool::class, ['method' => 'GET', 'uri' => 'ping']],
        'route.slash_stored' => [RouteToEventsTool::class, ['method' => 'get', 'uri' => '/ping']],
        'route.missing' => [RouteToEventsTool::class, ['method' => 'DELETE', 'uri' => 'nope']],
        'route.verb_mismatch' => [RouteToEventsTool::class, ['method' => 'GET', 'uri' => 'orders']],
        'impact.event' => [ImpactOfChangeTool::class, ['fqcn' => $order]],
        'impact.event_rename' => [ImpactOfChangeTool::class, ['fqcn' => $order, 'kind' => 'rename']],
        'impact.event_singular' => [ImpactOfChangeTool::class, ['fqcn' => 'App\\Events\\ReceiptSent']],
        'impact.event_none' => [ImpactOfChangeTool::class, ['fqcn' => 'App\\Events\\Lonely']],
        'impact.listener_orphans' => [ImpactOfChangeTool::class, ['fqcn' => 'App\\Listeners\\ArchiveReceipt']],
        'impact.listener_rename' => [ImpactOfChangeTool::class, ['fqcn' => 'App\\Listeners\\ArchiveReceipt', 'kind' => 'rename']],
        'impact.listener_no_orphans' => [ImpactOfChangeTool::class, ['fqcn' => 'App\\Listeners\\SendReceipt']],
        'impact.listener_would_orphan' => [ImpactOfChangeTool::class, ['fqcn' => 'App\\Listeners\\PingListener']],
        'impact.listener_idle' => [ImpactOfChangeTool::class, ['fqcn' => 'App\\Listeners\\Idle']],
        'impact.job' => [ImpactOfChangeTool::class, ['fqcn' => 'App\\Jobs\\SendMail']],
        'impact.unknown' => [ImpactOfChangeTool::class, ['fqcn' => 'App\\Nope']],
        'impact.bad_kind' => [ImpactOfChangeTool::class, ['fqcn' => $order, 'kind' => 'explode']],
        'orphans' => [FindOrphansTool::class, []],
        'unresolved' => [FindUnresolvedDispatchesTool::class, []],
        'entity.event' => [GetEntityTool::class, ['kind' => 'event', 'fqcn' => $order]],
        'entity.listener' => [GetEntityTool::class, ['kind' => 'listener', 'fqcn' => 'App\\Listeners\\PingListener']],
        'entity.observer' => [GetEntityTool::class, ['kind' => 'observer', 'fqcn' => 'App\\Observers\\OrderObserver']],
        'entity.job' => [GetEntityTool::class, ['kind' => 'job', 'fqcn' => 'App\\Jobs\\SendMail']],
        'entity.mailable' => [GetEntityTool::class, ['kind' => 'mailable', 'fqcn' => 'App\\Mail\\Receipt']],
        'entity.notification' => [GetEntityTool::class, ['kind' => 'notification', 'fqcn' => 'App\\Notifications\\Shipped']],
        'entity.unknown_kind' => [GetEntityTool::class, ['kind' => 'route', 'fqcn' => 'x']],
        'entity.missing' => [GetEntityTool::class, ['kind' => 'job', 'fqcn' => 'App\\Nope']],
        'entity.missing_arg' => [GetEntityTool::class, ['kind' => 'job']],
        'list.events' => [ListEntitiesTool::class, ['section' => 'events']],
        'list.listeners' => [ListEntitiesTool::class, ['section' => 'listeners']],
        'list.closure_listeners' => [ListEntitiesTool::class, ['section' => 'closure_listeners']],
        'list.observers' => [ListEntitiesTool::class, ['section' => 'observers']],
        'list.model_events' => [ListEntitiesTool::class, ['section' => 'model_events']],
        'list.jobs' => [ListEntitiesTool::class, ['section' => 'jobs']],
        'list.mailables' => [ListEntitiesTool::class, ['section' => 'mailables']],
        'list.notifications' => [ListEntitiesTool::class, ['section' => 'notifications']],
        'list.scheduled_empty' => [ListEntitiesTool::class, ['section' => 'scheduled']],
        'list.routes' => [ListEntitiesTool::class, ['section' => 'routes']],
        'list.unresolved' => [ListEntitiesTool::class, ['section' => 'unresolved_dispatches']],
        'list.unknown' => [ListEntitiesTool::class, ['section' => 'widgets']],
    ];
}

/** Raw text (content + errors) a tool call produced. */
function mcpCaptureText(string $tool, array $args): string
{
    $response = LoomMcpServer::tool($tool, $args);
    $read = static fn (string $method): array => (function () use ($method): array {
        return $this->{$method}();
    })->call($response);

    return json_encode(['content' => $read('content'), 'errors' => $read('errors')], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function mcpGoldenPath(): string
{
    return __DIR__.'/../../Fixtures/mcp-golden.json';
}

/** @return array<string, mixed> */
function mcpActualSnapshot(): array
{
    $snapshot = ['tools' => [], 'cases' => []];

    foreach (MCP_TOOLS as $class) {
        $tool = app($class);
        $snapshot['tools'][$tool->name()] = json_decode((string) json_encode($tool->toArray()), true);
    }

    foreach (mcpCharacterizationCases() as $name => [$tool, $args]) {
        $snapshot['cases'][$name] = json_decode(mcpCaptureText($tool, $args), true);
    }

    return $snapshot;
}

beforeEach(function () {
    mcpUseIndex(mcpTempIndex(require __DIR__.'/../../Fixtures/query-index.php'));
});

it('registers the same eleven tools on the server', function () {
    $prop = new ReflectionProperty(LoomMcpServer::class, 'tools');

    expect($prop->getDefaultValue())->toBe(MCP_TOOLS);
});

it('keeps tool output and schemas identical to the golden snapshot', function () {
    $actual = mcpActualSnapshot();

    if (getenv('LOOM_UPDATE_GOLDEN') === '1') {
        file_put_contents(mcpGoldenPath(), json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
    }

    $golden = json_decode((string) file_get_contents(mcpGoldenPath()), true);

    expect(array_keys($actual['tools']))->toBe(array_keys($golden['tools']));
    expect(array_keys($actual['cases']))->toBe(array_keys($golden['cases']));

    // Compare re-encoded strings so JSON key order is part of the contract.
    foreach ($golden['tools'] as $name => $expected) {
        expect(json_encode($actual['tools'][$name]))->toBe(json_encode($expected), "tool schema {$name}");
    }
    foreach ($golden['cases'] as $name => $expected) {
        expect(json_encode($actual['cases'][$name]))->toBe(json_encode($expected), "case {$name}");
    }
});

it('pins the error strings verbatim', function () {
    $errors = static fn (string $case): string => implode('|', mcpActualSnapshot()['cases'][$case]['errors']);

    expect($errors('route.missing'))->toContain('No route found for DELETE nope.')
        ->and($errors('entity.unknown_kind'))->toContain('Unknown kind [route].')
        ->and($errors('entity.missing'))->toContain('No job found for App\\Nope.')
        ->and($errors('list.unknown'))->toContain('Unknown section [widgets].')
        ->and($errors('impact.bad_kind'))->toContain('Unknown kind [explode]; expected one of: remove, rename.')
        ->and($errors('handlers.empty'))->toContain('event_fqcn is required.')
        ->and($errors('sites.empty'))->toContain('event_fqcn is required.')
        ->and($errors('dispatches_from.empty'))->toContain('method_fqcn is required.');
});
