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

#[Name('events-following')]
#[Description('Follow an event through its handlers, what those handlers dispatch, and the handlers of those events, recursively. Returns the transitive handler/dispatch chain rooted at the given event, bounded by depth (1..6, default 3).')]
final class EventsFollowingTool extends Tool
{
    public function __construct(private readonly IndexQuery $query)
    {
    }

    /** @return array<string, \Illuminate\JsonSchema\Types\Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'event_fqcn' => $schema->string()
                ->description('The fully-qualified class name of the event to follow.')
                ->required(),
            'depth' => $schema->integer()
                ->description('How many handler→dispatch hops to follow (clamped to 1..6).')
                ->default(ChainDepth::DEFAULT),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'event_fqcn' => 'required|string',
            'depth' => 'sometimes|integer',
        ]);

        $depth = (int) ($validated['depth'] ?? ChainDepth::DEFAULT);

        return Response::text((string) json_encode(
            $this->query->eventChain($validated['event_fqcn'], $depth)->toArray(),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }
}
