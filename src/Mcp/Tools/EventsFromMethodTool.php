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

/** @internal */
#[Name('events-from-method')]
#[Description('Transitive closure starting from a Class::method (or Class@method, or a bare Class for every method): what it dispatches, then the event chain following each dispatched event. Depth bounds the chain (1..6, default 3). Method granularity is exact only for routes; listeners/observers/jobs resolve at class level.')]
final class EventsFromMethodTool extends Tool
{
    public function __construct(private readonly IndexQuery $query)
    {
    }

    /** @return array<string, \Illuminate\JsonSchema\Types\Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'method_fqcn' => $schema->string()
                ->description('The method to start from, e.g. App\\Http\\Controllers\\OrderController::store.')
                ->required(),
            'depth' => $schema->integer()
                ->description('How many handler→dispatch hops to follow per chain (clamped to 1..6).')
                ->default(ChainDepth::DEFAULT),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'method_fqcn' => 'required|string',
            'depth' => 'sometimes|integer',
        ]);

        $depth = (int) ($validated['depth'] ?? ChainDepth::DEFAULT);

        return Response::text((string) json_encode(
            $this->query->eventsFromMethod($validated['method_fqcn'], $depth)->toArray(),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }
}
