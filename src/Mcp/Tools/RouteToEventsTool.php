<?php

declare(strict_types=1);

namespace Lucasp\Loom\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Lucasp\Loom\Index\Model\Route;
use Lucasp\Loom\Mcp\EventGraph;
use Lucasp\Loom\Mcp\IndexRepository;

#[Name('route-to-events')]
#[Description('Resolve an HTTP route (verb + URI) to the event chain its controller action triggers. Matches case-insensitively on verb and exactly on URI (leading slash optional), then follows the controller method through the dispatch graph (depth 1..6, default 3). Closure or unresolved-controller routes return an empty chain with a note.')]
final class RouteToEventsTool extends Tool
{
    private const DEPTH_MIN = 1;

    private const DEPTH_MAX = 6;

    private const DEPTH_DEFAULT = 3;

    public function __construct(
        private readonly EventGraph $graph,
        private readonly IndexRepository $repository,
    ) {
    }

    /** @return array<string, \Illuminate\JsonSchema\Types\Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'method' => $schema->string()
                ->description('The HTTP verb, e.g. GET or POST (case-insensitive).')
                ->required(),
            'uri' => $schema->string()
                ->description('The route URI, e.g. orders or /orders (leading slash optional).')
                ->required(),
            'depth' => $schema->integer()
                ->description('How many handler→dispatch hops to follow per chain (clamped to 1..6).')
                ->default(self::DEPTH_DEFAULT),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'method' => 'required|string',
            'uri' => 'required|string',
            'depth' => 'sometimes|integer',
        ]);

        $verb = strtoupper((string) $validated['method']);
        $uri = (string) $validated['uri'];
        $depth = max(self::DEPTH_MIN, min(self::DEPTH_MAX, (int) ($validated['depth'] ?? self::DEPTH_DEFAULT)));

        $route = $this->matchRoute($verb, $uri);

        if ($route === null) {
            return Response::error("No route found for {$verb} {$uri}.");
        }

        $routeView = $this->routeView($route);

        // Closure or unresolved-controller route: nothing to follow statically.
        if ($route->controllerFqcn === null) {
            return Response::text((string) json_encode([
                'route' => $routeView,
                'note' => 'Route has no resolved controller (closure or unresolved action); no event chain available.',
                'chain' => null,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        $methodFqcn = $route->controllerFqcn.'::'.($route->controllerMethod ?? '__invoke');

        return Response::text((string) json_encode([
            'route' => $routeView,
            'chain' => $this->graph->fromMethod($methodFqcn, $depth),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function matchRoute(string $verb, string $uri): ?Route
    {
        $candidates = [ltrim($uri, '/'), '/'.ltrim($uri, '/'), $uri];

        foreach ($this->repository->index()->routes() as $route) {
            if (strtoupper($route->method) !== $verb) {
                continue;
            }
            if (in_array($route->uri, $candidates, true) || ltrim($route->uri, '/') === ltrim($uri, '/')) {
                return $route;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function routeView(Route $route): array
    {
        return [
            'method' => $route->method,
            'uri' => $route->uri,
            'name' => $route->name,
            'controller_fqcn' => $route->controllerFqcn,
            'controller_method' => $route->controllerMethod,
            'middleware' => $route->middleware,
            'file' => $route->file,
            'line' => $route->line,
        ];
    }
}
