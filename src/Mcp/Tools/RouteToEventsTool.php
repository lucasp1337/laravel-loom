<?php

declare(strict_types=1);

namespace Lucasp\Loom\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Lucasp\Loom\Query\ChainDepth;
use Lucasp\Loom\Query\IndexQuery;

#[Name('route-to-events')]
#[Description('Resolve an HTTP route (verb + URI) to the event chain its controller action triggers. Matches case-insensitively on verb and exactly on URI (leading slash optional), then follows the controller method through the dispatch graph (depth 1..6, default 3). Closure or unresolved-controller routes return an empty chain with a note.')]
final class RouteToEventsTool extends Tool
{
    public function __construct(private readonly IndexQuery $query)
    {
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
                ->default(ChainDepth::DEFAULT),
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
        $depth = (int) ($validated['depth'] ?? ChainDepth::DEFAULT);

        $result = $this->query->routeChain($verb, $uri, $depth);

        if ($result === null) {
            return Response::error("No route found for {$verb} {$uri}.");
        }

        // Closure or unresolved-controller route: nothing to follow statically.
        if ($result->chain === null) {
            return Response::text((string) json_encode([
                'route' => $result->routeArray(),
                'note' => 'Route has no resolved controller (closure or unresolved action); no event chain available.',
                'chain' => null,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        return Response::text((string) json_encode(
            $result->toArray(),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }
}
