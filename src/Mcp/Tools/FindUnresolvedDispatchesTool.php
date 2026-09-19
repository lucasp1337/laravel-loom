<?php

declare(strict_types=1);

namespace Lucasp\Loom\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Lucasp\Loom\Index\Model\UnresolvedDispatch;
use Lucasp\Loom\Query\IndexQuery;

/** @internal */
#[Name('find-unresolved-dispatches')]
#[Description('List dispatch sites the scanner could not statically resolve to a target, with the raw expression, reason, file and line. Surfaces what static analysis could not pin down.')]
final class FindUnresolvedDispatchesTool extends Tool
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
        $unresolved = array_map(static fn (UnresolvedDispatch $u): array => [
            'file' => $u->file,
            'line' => $u->line,
            'expression' => $u->expression,
            'reason' => $u->reason,
        ], $this->query->unresolvedDispatches());

        return Response::text((string) json_encode([
            'count' => count($unresolved),
            'unresolved_dispatches' => $unresolved,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
