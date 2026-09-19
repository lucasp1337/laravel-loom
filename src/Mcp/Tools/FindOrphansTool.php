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

/** @internal */
#[Name('find-orphans')]
#[Description('List dead-weight in the event graph: orphan events (dispatched from nowhere AND handled by nothing) and idle listeners (registered but handling no events). A review aid — dynamically dispatched or reflection-registered cases may not surface statically.')]
final class FindOrphansTool extends Tool
{
    public function __construct(private readonly IndexQuery $query)
    {
    }

    /** @return array<string, \Illuminate\JsonSchema\Types\Type> */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response
    {
        return Response::text((string) json_encode(
            $this->query->orphans()->toArray(),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }
}
