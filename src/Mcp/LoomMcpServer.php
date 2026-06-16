<?php

declare(strict_types=1);

namespace Lucasp\Loom\Mcp;

use Laravel\Mcp\Server;
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
 * The embedded Loom MCP server: a stdio, read-only tool surface over the Loom
 * index, registered as `Mcp::local('loom', ...)` and started by `loom:mcp`.
 */
final class LoomMcpServer extends Server
{
    protected string $name = 'Laravel Loom';

    protected string $version = '0.1.0';

    protected string $instructions = <<<'MARKDOWN'
        Laravel Loom statically maps a Laravel app's event-driven architecture:
        events, listeners, observers, jobs, routes and how they dispatch one
        another. Use these tools instead of grepping source — they return a
        structured graph (FQCNs, dispatch sites, handler chains) from a
        pre-built index.

        Start with `list-entities` / `get-entity` to find a class, the edge
        tools (`dispatch-sites-for`, `handlers-for`, `dispatches-from`) to walk
        one hop, and the chain tools (`events-following`, `events-from-method`,
        `route-to-events`) to follow dispatches transitively. `impact-of-change`,
        `find-orphans` and `find-unresolved-dispatches` answer review questions.
        MARKDOWN;

    /** @var array<int, class-string<\Laravel\Mcp\Server\Tool>> */
    protected array $tools = [
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
}
