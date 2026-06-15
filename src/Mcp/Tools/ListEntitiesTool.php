<?php

declare(strict_types=1);

namespace Lucasp\Loom\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Lucasp\Loom\Index\Sections;
use Lucasp\Loom\Mcp\IndexRepository;
use ValueError;

#[Name('list-entities')]
#[Description('List every entity in one index section (events, listeners, observers, jobs, routes, ...) verbatim from the index, with a count. Use this to enumerate what the scanner found before drilling into a single entity with get-entity.')]
final class ListEntitiesTool extends Tool
{
    public function __construct(private readonly IndexRepository $repository)
    {
    }

    /** @return array<string, \Illuminate\JsonSchema\Types\Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'section' => $schema->string()
                ->enum(array_map(static fn (Sections $s): string => $s->value, Sections::cases()))
                ->description('The index section to list.')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'section' => 'required|string',
        ]);

        try {
            $section = Sections::from($validated['section']);
        } catch (ValueError) {
            return Response::error("Unknown section [{$validated['section']}].");
        }

        $items = $this->repository->payload()[$section->value] ?? [];
        $items = is_array($items) ? $items : [];

        return Response::text((string) json_encode([
            'section' => $section->value,
            'count' => count($items),
            'items' => $items,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
