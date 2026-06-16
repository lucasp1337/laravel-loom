<?php

declare(strict_types=1);

namespace Lucasp\Loom\Mcp;

use Lucasp\Loom\Index\DispatchKinds;
use Lucasp\Loom\Index\Index;
use Lucasp\Loom\Index\Model\Dispatch;

/**
 * Read-only traversal of the event/dispatch graph, built on the Index read
 * model. Powers the MCP "chain" tools: following an event through its handlers'
 * dispatches, starting from a method, and impact analysis. All traversal is
 * cycle-safe (a visited set bounds recursion) and depth-bounded.
 *
 * Granularity note: a listener/observer/job exposes dispatches at the class
 * level (the read model does not tag a dispatch with the originating method),
 * so method-level resolution is exact only for routes, which carry a resolved
 * (controller, method) pair. Class-level matches are returned for the rest.
 */
final class EventGraph
{
    public function __construct(private readonly Index $index)
    {
    }

    /**
     * Events and jobs dispatched from a `Class::method` (or `Class@method`, or
     * a bare `Class` for every method). Routes resolve at method granularity;
     * listeners/observers/jobs resolve at class granularity.
     *
     * @return list<array{target: string, kind: string, confidence: string, file: string, line: int}>
     */
    public function dispatchesFromMethod(string $methodFqcn): array
    {
        [$class, $method] = $this->splitMethod($methodFqcn);

        $out = [];

        foreach ($this->index->routes() as $route) {
            if ($route->controllerFqcn === $class
                && ($method === null || $route->controllerMethod === $method)) {
                $out = [...$out, ...$route->dispatches];
            }
        }

        foreach ($this->index->listeners() as $listener) {
            if ($listener->fqcn === $class) {
                $out = [...$out, ...$listener->dispatches];
            }
        }

        foreach ($this->index->observers() as $observer) {
            if ($observer->fqcn === $class) {
                $out = [...$out, ...$observer->dispatches];
            }
        }

        foreach ($this->index->jobs() as $job) {
            if ($job->fqcn === $class) {
                $out = [...$out, ...$job->dispatches];
            }
        }

        return $this->dedupeDispatches($out);
    }

    /**
     * Transitive chain rooted at an event: its handlers, what they dispatch,
     * and recursively the handlers of those dispatched events, bounded by depth.
     *
     * @return array{root: string, depth: int, edges: list<array<string, mixed>>, events_reached: list<string>}
     */
    public function following(string $eventFqcn, int $depth = 3): array
    {
        $edges = [];
        $reached = [];
        $visited = [];

        $frontier = [$eventFqcn];

        for ($level = 0; $level < $depth && $frontier !== []; $level++) {
            $next = [];

            foreach ($frontier as $event) {
                if (isset($visited[$event])) {
                    continue;
                }
                $visited[$event] = true;
                $reached[] = $event;

                foreach ($this->handlersOf($event) as $handler) {
                    $dispatches = $this->dispatchList($handler['dispatches']);
                    $edges[] = [
                        'event' => $event,
                        'handler' => $handler['ref'],
                        'handler_kind' => $handler['kind'],
                        'dispatches' => $dispatches,
                    ];

                    foreach ($dispatches as $dispatch) {
                        if ($dispatch['kind'] === DispatchKinds::EVENT->value && ! isset($visited[$dispatch['target']])) {
                            $next[] = $dispatch['target'];
                        }
                    }
                }
            }

            $frontier = $next;
        }

        return [
            'root' => $eventFqcn,
            'depth' => $depth,
            'edges' => $edges,
            'events_reached' => array_values(array_unique($reached)),
        ];
    }

    /**
     * Transitive closure starting from a method: what it dispatches, then the
     * event chain following each dispatched event.
     *
     * @return array{method: string, dispatches: list<array<string, mixed>>, chains: list<array<string, mixed>>}
     */
    public function fromMethod(string $methodFqcn, int $depth = 3): array
    {
        $dispatches = $this->dispatchesFromMethod($methodFqcn);

        $chains = [];
        foreach ($dispatches as $dispatch) {
            if ($dispatch['kind'] === DispatchKinds::EVENT->value) {
                $chains[] = $this->following($dispatch['target'], $depth);
            }
        }

        return [
            'method' => $methodFqcn,
            'dispatches' => $dispatches,
            'chains' => $chains,
        ];
    }

    /**
     * Handlers of an event: named listeners (via `handled_by`) and closure
     * listeners bound to the event, each with its class-level dispatches.
     *
     * @return list<array{ref: string, kind: string, dispatches: list<Dispatch>}>
     */
    private function handlersOf(string $eventFqcn): array
    {
        $handlers = [];

        foreach ($this->index->handlersOf($eventFqcn) as $handler) {
            $listener = $this->index->findListener($handler->listener);
            $handlers[] = [
                'ref' => $handler->listener.'::'.$handler->method,
                'kind' => 'listener',
                'dispatches' => $listener !== null ? $listener->dispatches : [],
            ];
        }

        foreach ($this->index->closureListeners() as $closure) {
            if ($closure->event === $eventFqcn) {
                $handlers[] = [
                    'ref' => $closure->file.':'.$closure->line,
                    'kind' => 'closure',
                    'dispatches' => $closure->dispatches,
                ];
            }
        }

        return $handlers;
    }

    /**
     * @param  list<Dispatch>  $dispatches
     * @return list<array{target: string, kind: string, confidence: string, file: string, line: int}>
     */
    private function dispatchList(array $dispatches): array
    {
        return array_map(static fn (Dispatch $d): array => [
            'target' => $d->target,
            'kind' => $d->kind->value,
            'confidence' => $d->confidence->value,
            'file' => $d->file,
            'line' => $d->line,
        ], $dispatches);
    }

    /**
     * @param  list<Dispatch>  $dispatches
     * @return list<array{target: string, kind: string, confidence: string, file: string, line: int}>
     */
    private function dedupeDispatches(array $dispatches): array
    {
        $seen = [];
        $out = [];
        foreach ($this->dispatchList($dispatches) as $dispatch) {
            $key = $dispatch['target'].'|'.$dispatch['file'].':'.$dispatch['line'];
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $out[] = $dispatch;
            }
        }

        return $out;
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    private function splitMethod(string $methodFqcn): array
    {
        foreach (['::', '@'] as $sep) {
            $pos = strrpos($methodFqcn, $sep);
            if ($pos !== false) {
                return [substr($methodFqcn, 0, $pos), substr($methodFqcn, $pos + strlen($sep))];
            }
        }

        return [$methodFqcn, null];
    }
}
