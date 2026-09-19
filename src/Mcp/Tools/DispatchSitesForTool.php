<?php

declare(strict_types=1);

namespace Lucasp\Loom\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Lucasp\Loom\Query\IndexQuery;

#[Name('dispatch-sites-for')]
#[Description('Where is this event dispatched? Returns every source location (file, line, method) that dispatches the given event class, from the static index.')]
final class DispatchSitesForTool extends Tool
{
    public function __construct(private readonly IndexQuery $query)
    {
    }

    /** @return array<string, \Illuminate\JsonSchema\Types\Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'event_fqcn' => $schema->string()
                ->description('Fully-qualified event class name, e.g. App\\Events\\OrderPlaced.')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $eventFqcn = (string) $request->get('event_fqcn');

        if ($eventFqcn === '') {
            return Response::error('event_fqcn is required.');
        }

        return Response::text((string) json_encode(
            $this->query->dispatchSitesFor($eventFqcn)->toArray(),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }
}
