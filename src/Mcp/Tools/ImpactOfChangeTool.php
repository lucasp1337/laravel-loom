<?php

declare(strict_types=1);

namespace Lucasp\Loom\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Lucasp\Loom\Index\Index;
use Lucasp\Loom\Mcp\EventGraph;
use Lucasp\Loom\Mcp\IndexRepository;

#[Name('impact-of-change')]
#[Description('Blast-radius report for removing or renaming a class. For an event: who dispatches it, who handles it (named + closure listeners), and the transitive downstream chain. For a listener/job: which events it handles and whether removing it would orphan an event (an event whose only handler is this class). Notes call out what static analysis cannot determine.')]
final class ImpactOfChangeTool extends Tool
{
    /** @var list<string> */
    private const KINDS = ['remove', 'rename'];

    private const DOWNSTREAM_DEPTH = 3;

    public function __construct(
        private readonly EventGraph $graph,
        private readonly IndexRepository $repository,
    ) {
    }

    /** @return array<string, \Illuminate\JsonSchema\Types\Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'fqcn' => $schema->string()
                ->description('The fully-qualified class name being changed (event, listener, or job).')
                ->required(),
            'kind' => $schema->string()
                ->enum(self::KINDS)
                ->description('The kind of change. Affects the framing of the notes only.')
                ->default('remove'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'fqcn' => 'required|string',
            'kind' => 'sometimes|string',
        ]);

        $fqcn = (string) $validated['fqcn'];
        $kind = $validated['kind'] ?? 'remove';
        if (! in_array($kind, self::KINDS, true)) {
            return Response::error("Unknown kind [{$kind}]; expected one of: ".implode(', ', self::KINDS).'.');
        }

        $index = $this->repository->index();

        $report = $index->findEvent($fqcn) !== null
            ? $this->eventReport($index, $fqcn, $kind)
            : $this->nonEventReport($index, $fqcn, $kind);

        return Response::text((string) json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @return array<string, mixed> */
    private function eventReport(Index $index, string $fqcn, string $kind): array
    {
        $dispatchers = array_map(static fn ($site): array => [
            'file' => $site->file,
            'line' => $site->line,
            'method' => $site->method,
        ], $index->dispatchersOf($fqcn));

        $handlers = array_map(static fn ($handler): array => [
            'listener' => $handler->listener,
            'method' => $handler->method,
            'kind' => 'listener',
        ], $index->handlersOf($fqcn));

        foreach ($index->closureListeners() as $closure) {
            if ($closure->event === $fqcn) {
                $handlers[] = [
                    'listener' => $closure->file.':'.$closure->line,
                    'method' => 'closure',
                    'kind' => 'closure',
                ];
            }
        }

        $notes = [
            $kind === 'rename'
                ? "Renaming this event requires updating all {$this->plural(count($dispatchers), 'dispatch site')} and {$this->plural(count($handlers), 'handler')} below."
                : "Removing this event orphans its {$this->plural(count($handlers), 'handler')}; its {$this->plural(count($dispatchers), 'dispatch site')} would dispatch a missing class.",
            'Dynamic dispatches (event($var), string class names) are not statically resolvable and may not appear here.',
        ];

        return [
            'fqcn' => $fqcn,
            'kind' => $kind,
            'entity' => 'event',
            'dispatchers' => $dispatchers,
            'handlers' => $handlers,
            'downstream' => $this->graph->following($fqcn, self::DOWNSTREAM_DEPTH),
            'notes' => $notes,
        ];
    }

    /** @return array<string, mixed> */
    private function nonEventReport(Index $index, string $fqcn, string $kind): array
    {
        $listener = $index->findListener($fqcn);
        $job = $index->findJob($fqcn);

        if ($listener === null && $job === null) {
            return [
                'fqcn' => $fqcn,
                'kind' => $kind,
                'entity' => 'unknown',
                'dispatchers' => [],
                'handlers' => [],
                'downstream' => null,
                'notes' => ['No event, listener, or job in the index matches this FQCN. It may be unscanned, dynamically referenced, or misspelled.'],
            ];
        }

        $handledEvents = $listener === null
            ? []
            : array_map(static fn ($handle): string => $handle->event, $listener->handles);

        // Events this listener handles whose ONLY handler is this listener would
        // be orphaned (left unhandled) if it is removed.
        $orphaned = [];
        foreach ($handledEvents as $eventFqcn) {
            $others = array_filter(
                $index->handlersOf($eventFqcn),
                static fn ($handler): bool => $handler->listener !== $fqcn,
            );
            $hasClosure = false;
            foreach ($index->closureListeners() as $closure) {
                if ($closure->event === $eventFqcn) {
                    $hasClosure = true;
                    break;
                }
            }
            if ($others === [] && ! $hasClosure) {
                $orphaned[] = $eventFqcn;
            }
        }

        $matched = $listener ?? $job;
        $dispatches = array_map(static fn ($d): array => [
            'target' => $d->target,
            'kind' => $d->kind->value,
            'confidence' => $d->confidence->value,
            'file' => $d->file,
            'line' => $d->line,
        ], $matched->dispatches);

        $notes = [];
        if ($orphaned !== []) {
            $notes[] = ($kind === 'remove' ? 'Removing' : 'Renaming')
                .' this class would leave '.$this->plural(count($orphaned), 'event').' with no remaining handler: '.implode(', ', $orphaned).'.';
        } else {
            $notes[] = 'No handled event would be left without a handler by this change.';
        }
        $notes[] = 'Downstream dispatches from this class are listed; their own chains are not expanded here.';

        return [
            'fqcn' => $fqcn,
            'kind' => $kind,
            'entity' => $listener !== null ? 'listener' : 'job',
            'handles' => $handledEvents,
            'would_orphan_events' => $orphaned,
            'dispatches' => $dispatches,
            'notes' => $notes,
        ];
    }

    private function plural(int $count, string $noun): string
    {
        return $count.' '.$noun.($count === 1 ? '' : 's');
    }
}
