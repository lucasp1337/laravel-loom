<?php

declare(strict_types=1);

namespace Lucasp\Loom\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Lucasp\Loom\Query\EntityKind;
use Lucasp\Loom\Query\IndexQuery;

#[Name('get-entity')]
#[Description('Fetch a single entity by kind (event, listener, observer, job, mailable, notification) and fully-qualified class name, returning its full read-model record including dispatch sites and handler chains where applicable.')]
final class GetEntityTool extends Tool
{
    public function __construct(private readonly IndexQuery $query)
    {
    }

    /** @return array<string, \Illuminate\JsonSchema\Types\Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'kind' => $schema->string()
                ->enum(array_map(static fn (EntityKind $k): string => $k->value, EntityKind::cases()))
                ->description('The kind of entity to fetch.')
                ->required(),
            'fqcn' => $schema->string()
                ->description('The fully-qualified class name of the entity.')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'kind' => 'required|string',
            'fqcn' => 'required|string',
        ]);

        $fqcn = $validated['fqcn'];

        $kind = EntityKind::tryFrom($validated['kind']);
        if ($kind === null) {
            return Response::error("Unknown kind [{$validated['kind']}].");
        }

        $entity = $this->query->entity($kind, $fqcn);

        if ($entity === null) {
            return Response::error("No {$kind->value} found for {$fqcn}.");
        }

        return Response::text((string) json_encode([
            'kind' => $kind->value,
            'fqcn' => $fqcn,
            'found' => true,
            'entity' => $entity,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
