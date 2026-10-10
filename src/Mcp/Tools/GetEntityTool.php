<?php

declare(strict_types=1);

namespace Lucasp\Loom\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Lucasp\Loom\Query\EntityKind;
use Lucasp\Loom\Query\IndexQuery;

/** @internal */
#[Name('get-entity')]
#[Description('Fetch a single entity by kind (event, listener, observer, job, mailable, notification) and fully-qualified class name, returning the raw index entry (snake_case, exactly as in the index schema).')]
final class GetEntityTool extends LoomTool
{
    public function __construct(private readonly IndexQuery $query) {}

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'kind' => $schema->string()
                ->enum(self::enumValues(EntityKind::class))
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
            return $this->unknownValue('kind', $validated['kind'], EntityKind::class);
        }

        $entity = $this->query->rawEntity($kind, $fqcn);

        if ($entity === null) {
            return Response::error("No {$kind->value} found for {$fqcn}.");
        }

        return $this->json([
            'kind' => $kind->value,
            'fqcn' => $fqcn,
            'found' => true,
            'entity' => $entity,
        ]);
    }
}
