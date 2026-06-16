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

#[Name('dispatches-from')]
#[Description('Given a method, what does it directly dispatch? Returns the events and jobs dispatched from a Class::method (also accepts Class@method or a bare Class), with kind, confidence, file and line.')]
final class DispatchesFromTool extends Tool
{
    public function __construct(private readonly EventGraph $graph)
    {
    }

    /** @return array<string, \Illuminate\JsonSchema\Types\Type> */
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

        $dispatches = $this->graph->dispatchesFromMethod($methodFqcn);

        return Response::text((string) json_encode([
            'method' => $methodFqcn,
            'count' => count($dispatches),
            'dispatches' => $dispatches,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
