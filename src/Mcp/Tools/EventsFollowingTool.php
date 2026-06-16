<?php

declare(strict_types=1);

namespace Lucasp\Loom\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Lucasp\Loom\Mcp\EventGraph;

#[Name('events-following')]
#[Description('Follow an event through its handlers, what those handlers dispatch, and the handlers of those events, recursively. Returns the transitive handler/dispatch chain rooted at the given event, bounded by depth (1..6, default 3).')]
final class EventsFollowingTool extends Tool
{
    private const DEPTH_MIN = 1;

    private const DEPTH_MAX = 6;

    private const DEPTH_DEFAULT = 3;

    public function __construct(private readonly EventGraph $graph)
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
                ->default(self::DEPTH_DEFAULT),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'event_fqcn' => 'required|string',
            'depth' => 'sometimes|integer',
        ]);

        $depth = max(self::DEPTH_MIN, min(self::DEPTH_MAX, (int) ($validated['depth'] ?? self::DEPTH_DEFAULT)));

        return Response::text((string) json_encode(
            $this->graph->following($validated['event_fqcn'], $depth),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }
}
