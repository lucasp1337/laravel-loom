<?php

declare(strict_types=1);

namespace Lucasp\Loom\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Arr;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Lucasp\Loom\Query\Dto\DispatchRef;
use Lucasp\Loom\Query\IndexQuery;

/** @internal */
#[Name('dispatches-from')]
#[Description('Given a method, what does it directly dispatch? Returns the events and jobs dispatched from a Class::method (also accepts Class@method or a bare Class), with kind, confidence, file and line.')]
final class DispatchesFromTool extends LoomTool
{
    public function __construct(private readonly IndexQuery $query) {}

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'method_fqcn' => $schema->string()
                ->description('Method reference: Class::method, Class@method, or a bare Class for every method, e.g. App\\Http\\Controllers\\OrderController::store.')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $methodFqcn = (string) $request->get('method_fqcn');

        if ($methodFqcn === '') {
            return Response::error('method_fqcn is required.');
        }

        $dispatches = Arr::map($this->query->dispatchesFrom($methodFqcn), static fn (DispatchRef $d): array => $d->toArray());

        return $this->json([
            'method_fqcn' => $methodFqcn,
            'count' => count($dispatches),
            'dispatches' => $dispatches,
        ]);
    }
}
