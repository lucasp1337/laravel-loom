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

#[Name('find-unresolved-dispatches')]
#[Description('List dispatch sites the scanner could not statically resolve to a target, with the raw expression, reason, file and line. Surfaces what static analysis could not pin down.')]
final class FindUnresolvedDispatchesTool extends Tool
{
    public function __construct(private readonly IndexRepository $repository)
    {
    }

    /** @return array<string, \Illuminate\JsonSchema\Types\Type> */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response
    {
        $unresolved = $this->repository->payload()[Sections::UNRESOLVED_DISPATCHES->value] ?? [];

        return Response::text((string) json_encode([
            'count' => is_array($unresolved) ? count($unresolved) : 0,
            'unresolved_dispatches' => $unresolved,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
