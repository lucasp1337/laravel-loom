<?php

declare(strict_types=1);

namespace Lucasp\Loom\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Lucasp\Loom\Index\Model\Handler;
use Lucasp\Loom\Mcp\IndexRepository;

#[Name('handlers-for')]
#[Description('Who handles this event? Returns the named listeners (class, method, queued) and anonymous closure listeners (file, line, queued) bound to the given event class.')]
final class HandlersForTool extends Tool
{
    public function __construct(private readonly IndexRepository $repository)
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

        $index = $this->repository->index();

        $listeners = array_map(function (Handler $handler) use ($index): array {
            $listener = $index->findListener($handler->listener);

            return [
                'listener' => $handler->listener,
                'method' => $handler->method,
                'queued' => $listener !== null && $listener->queued,
            ];
        }, $index->handlersOf($eventFqcn));

        $closureListeners = [];
        foreach ($index->closureListeners() as $closure) {
            if ($closure->event === $eventFqcn) {
                $closureListeners[] = [
                    'file' => $closure->file,
                    'line' => $closure->line,
                    'queued' => $closure->queued,
                ];
            }
        }

        return Response::text((string) json_encode([
            'event' => $eventFqcn,
            'listeners' => $listeners,
            'closure_listeners' => $closureListeners,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
