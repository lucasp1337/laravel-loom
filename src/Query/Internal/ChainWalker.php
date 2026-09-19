<?php

declare(strict_types=1);

namespace Lucasp\Loom\Query\Internal;

use Lucasp\Loom\Index\DispatchKinds;
use Lucasp\Loom\Index\Index;
use Lucasp\Loom\Index\Model\Dispatch;
use Lucasp\Loom\Query\Dto\ChainCycle;
use Lucasp\Loom\Query\Dto\ChainEdge;
use Lucasp\Loom\Query\Dto\DispatchRef;
use Lucasp\Loom\Query\Dto\EventChain;
use Lucasp\Loom\Query\HandlerKind;

/** Depth-bounded, cycle-safe walk of the event/dispatch graph. Dispatches are class-level except on routes. */
final class ChainWalker
{
    public function __construct(private readonly Index $index)
    {
    }

    /** @param  int  $depth  already clamped by the caller */
    public function chain(string $eventFqcn, int $depth): EventChain
    {
        $edges = [];
        $reached = [];
        $cycles = [];
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
                    $edges[] = new ChainEdge(
                        $event,
                        $handler->ref,
                        $handler->kind,
                        $handler->dispatches,
                        $level,
                        $handler->file,
                        $handler->line,
                    );

                    foreach ($handler->dispatches as $dispatch) {
                        if ($dispatch->kind !== DispatchKinds::EVENT) {
                            continue;
                        }
                        if (isset($visited[$dispatch->target])) {
                            $cycles[] = new ChainCycle($handler->ref, $dispatch->target);
                        } else {
                            $next[] = $dispatch->target;
                        }
                    }
                }
            }

            $frontier = $next;
        }

        $truncated = false;
        foreach ($frontier as $event) {
            if (! isset($visited[$event])) {
                $truncated = true;
                break;
            }
        }

        return new EventChain($eventFqcn, $depth, $edges, $reached, $cycles, $truncated);
    }

    /**
     * Dispatches from `Class::method`, `Class@method` or a bare `Class` (every
     * method), deduplicated on `target|file:line`.
     *
     * @return list<DispatchRef>
     */
    public function dispatchesFrom(string $methodFqcn): array
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

        return $this->dedupe($out);
    }

    /**
     * Named listeners (via `handled_by`) and closure listeners bound to the event.
     *
     * @return list<WalkedHandler>
     */
    private function handlersOf(string $eventFqcn): array
    {
        $handlers = [];

        foreach ($this->index->handlersOf($eventFqcn) as $handler) {
            $listener = $this->index->findListener($handler->listener);
            $handlers[] = new WalkedHandler(
                $handler->listener.'::'.$handler->method,
                HandlerKind::LISTENER,
                $listener?->file,
                $listener?->line,
                $this->refs($listener !== null ? $listener->dispatches : []),
            );
        }

        foreach ($this->index->closureListeners() as $closure) {
            if ($closure->event === $eventFqcn) {
                $handlers[] = new WalkedHandler(
                    $closure->file.':'.$closure->line,
                    HandlerKind::CLOSURE,
                    $closure->file,
                    $closure->line,
                    $this->refs($closure->dispatches),
                );
            }
        }

        return $handlers;
    }

    /**
     * @param  list<Dispatch>  $dispatches
     * @return list<DispatchRef>
     */
    private function refs(array $dispatches): array
    {
        return array_map(DispatchRef::fromDispatch(...), $dispatches);
    }

    /**
     * @param  list<Dispatch>  $dispatches
     * @return list<DispatchRef>
     */
    private function dedupe(array $dispatches): array
    {
        $seen = [];
        $out = [];
        foreach ($this->refs($dispatches) as $ref) {
            $key = $ref->target.'|'.$ref->file.':'.$ref->line;
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $out[] = $ref;
            }
        }

        return $out;
    }

    /** @return array{0: string, 1: ?string} */
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
