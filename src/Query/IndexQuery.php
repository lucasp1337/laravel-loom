<?php

declare(strict_types=1);

namespace Lucasp\Loom\Query;

use Lucasp\Loom\Index\DispatchKinds;
use Lucasp\Loom\Index\Field;
use Lucasp\Loom\Index\Index;
use Lucasp\Loom\Index\Model\Route;
use Lucasp\Loom\Index\Model\UnresolvedDispatch;
use Lucasp\Loom\Index\SectionRegistry;
use Lucasp\Loom\Index\Sections;
use Lucasp\Loom\Query\Dto\ClosureHandler;
use Lucasp\Loom\Query\Dto\Dashboard;
use Lucasp\Loom\Query\Dto\DispatchRef;
use Lucasp\Loom\Query\Dto\DispatchSiteSet;
use Lucasp\Loom\Query\Dto\EventChain;
use Lucasp\Loom\Query\Dto\FanOut;
use Lucasp\Loom\Query\Dto\HandlerRef;
use Lucasp\Loom\Query\Dto\HandlerSet;
use Lucasp\Loom\Query\Dto\ImpactReport;
use Lucasp\Loom\Query\Dto\IndexMeta;
use Lucasp\Loom\Query\Dto\ListenerHandler;
use Lucasp\Loom\Query\Dto\MethodChain;
use Lucasp\Loom\Query\Dto\Orphans;
use Lucasp\Loom\Query\Dto\Page;
use Lucasp\Loom\Query\Dto\RouteChain;
use Lucasp\Loom\Query\Dto\SearchHit;
use Lucasp\Loom\Query\Dto\SectionInfo;
use Lucasp\Loom\Query\Dto\SectionQuery;
use Lucasp\Loom\Query\Internal\ChainWalker;
use Lucasp\Loom\Query\Internal\Searcher;
use Lucasp\Loom\Query\Internal\SectionReader;

/**
 * Transport-agnostic questions over the Loom index, shared by the embedded MCP
 * server and the UI. Reads the index from its {@see IndexSource} on every call
 * so a rewritten snapshot is picked up.
 *
 * @internal
 */
final class IndexQuery
{
    public function __construct(private readonly IndexSource $source)
    {
    }

    // Graph -------------------------------------------------------------

    public function eventChain(string $eventFqcn, int $depth = ChainDepth::DEFAULT): EventChain
    {
        return (new ChainWalker($this->index()))->chain($eventFqcn, ChainDepth::clamp($depth));
    }

    /** Dispatches from `Class::method`, `Class@method` or a bare `Class`, then the chain of each dispatched event. */
    public function eventsFromMethod(string $methodFqcn, int $depth = ChainDepth::DEFAULT): MethodChain
    {
        $walker = new ChainWalker($this->index());
        $depth = ChainDepth::clamp($depth);
        $dispatches = $walker->dispatchesFrom($methodFqcn);

        $chains = [];
        foreach ($dispatches as $dispatch) {
            if ($dispatch->kind === DispatchKinds::EVENT) {
                $chains[] = $walker->chain($dispatch->target, $depth);
            }
        }

        return new MethodChain($methodFqcn, $dispatches, $chains);
    }

    /** @return list<DispatchRef> */
    public function dispatchesFrom(string $classOrMethod): array
    {
        return (new ChainWalker($this->index()))->dispatchesFrom($classOrMethod);
    }

    public function handlersFor(string $eventFqcn): HandlerSet
    {
        $index = $this->index();

        $listeners = array_map(function ($handler) use ($index): ListenerHandler {
            $listener = $index->findListener($handler->listener);

            return new ListenerHandler($handler->listener, $handler->method, $listener !== null && $listener->queued);
        }, $index->handlersOf($eventFqcn));

        $closures = [];
        foreach ($index->closureListeners() as $closure) {
            if ($closure->event === $eventFqcn) {
                $closures[] = new ClosureHandler($closure->file, $closure->line, $closure->queued);
            }
        }

        return new HandlerSet($eventFqcn, $listeners, $closures);
    }

    public function dispatchSitesFor(string $eventFqcn): DispatchSiteSet
    {
        return new DispatchSiteSet($eventFqcn, $this->index()->dispatchersOf($eventFqcn));
    }

    /** Matches the verb case-insensitively and the URI with or without a leading slash. */
    public function routeFor(string $verb, string $uri): ?Route
    {
        $verb = strtoupper($verb);
        $bare = ltrim($uri, '/');

        foreach ($this->index()->routes() as $route) {
            if (strtoupper($route->method) === $verb && ltrim($route->uri, '/') === $bare) {
                return $route;
            }
        }

        return null;
    }

    public function routeChain(string $verb, string $uri, int $depth = ChainDepth::DEFAULT): ?RouteChain
    {
        $route = $this->routeFor($verb, $uri);
        if ($route === null) {
            return null;
        }

        if ($route->controllerFqcn === null) {
            return new RouteChain($route, null);
        }

        $method = $route->controllerFqcn.'::'.($route->controllerMethod ?? '__invoke');

        return new RouteChain($route, $this->eventsFromMethod($method, $depth));
    }

    public function impactOfChange(string $fqcn, ChangeKind $kind = ChangeKind::REMOVE): ImpactReport
    {
        $index = $this->index();

        if ($index->findEvent($fqcn) !== null) {
            return $this->eventImpact($index, $fqcn, $kind);
        }

        $listener = $index->findListener($fqcn);
        $job = $index->findJob($fqcn);

        if ($listener === null && $job === null) {
            return new ImpactReport($fqcn, $kind, ImpactEntity::UNKNOWN, notes: [ImpactNote::UNKNOWN_FQCN]);
        }

        $handles = $listener === null
            ? []
            : array_map(static fn ($handle): string => $handle->event, $listener->handles);

        $closureEvents = [];
        foreach ($index->closureListeners() as $closure) {
            $closureEvents[$closure->event] = true;
        }

        // An event whose only handler is this class is left unhandled by removing it.
        $orphaned = [];
        foreach ($handles as $eventFqcn) {
            $others = array_filter(
                $index->handlersOf($eventFqcn),
                static fn ($handler): bool => $handler->listener !== $fqcn,
            );
            if ($others === [] && ! isset($closureEvents[$eventFqcn])) {
                $orphaned[] = $eventFqcn;
            }
        }

        $matched = $listener ?? $job;
        $dispatches = array_map(DispatchRef::fromDispatch(...), $matched->dispatches);

        return new ImpactReport(
            $fqcn,
            $kind,
            $listener !== null ? ImpactEntity::LISTENER : ImpactEntity::JOB,
            handles: $handles,
            wouldOrphanEvents: $orphaned,
            dispatches: $dispatches,
            notes: [
                $orphaned !== [] ? ImpactNote::WOULD_ORPHAN_EVENTS : ImpactNote::NO_ORPHANS,
                ImpactNote::DOWNSTREAM_NOT_EXPANDED,
            ],
        );
    }

    // Entities and sections ----------------------------------------------

    public function entity(EntityKind $kind, string $fqcn): ?object
    {
        $index = $this->index();

        return match ($kind) {
            EntityKind::EVENT => $index->findEvent($fqcn),
            EntityKind::LISTENER => $index->findListener($fqcn),
            EntityKind::OBSERVER => $index->findObserver($fqcn),
            EntityKind::JOB => $index->findJob($fqcn),
            EntityKind::MAILABLE => $index->findMailable($fqcn),
            EntityKind::NOTIFICATION => $index->findNotification($fqcn),
        };
    }

    /**
     * The raw index entry (snake_case, as in the schema) for an entity, or null.
     *
     * @return array<string, mixed>|null
     */
    public function rawEntity(EntityKind $kind, string $fqcn): ?array
    {
        $needle = ltrim($fqcn, '\\');
        foreach ($this->index()->sections[$kind->section()->value] ?? [] as $entry) {
            $candidate = $entry[Field::FQCN->value] ?? null;
            if (is_string($candidate) && ltrim($candidate, '\\') === $needle) {
                return $entry;
            }
        }

        return null;
    }

    /** @return list<SectionInfo> in index body order */
    public function sections(): array
    {
        $index = $this->index();
        $out = [];

        foreach (SectionRegistry::DESCRIPTORS as $descriptor) {
            $section = $descriptor['section'];
            $out[] = new SectionInfo(
                $section,
                count($index->sections[$section->value] ?? []),
                array_key_exists($section->value, $index->sections),
                $descriptor['inStats'],
                EntityKind::forSection($section),
            );
        }

        return $out;
    }

    public function list(Sections $section, ?SectionQuery $query = null): Page
    {
        $query ??= new SectionQuery;
        $items = SectionReader::items($this->index(), $section);

        $needle = $query->search === null ? '' : mb_strtolower(trim($query->search));
        $items = array_values(array_filter($items, function (object $item) use ($query, $needle): bool {
            foreach ($query->filters as $property => $expected) {
                if (SectionReader::comparable($item, $property) !== SectionReader::normalise($expected)) {
                    return false;
                }
            }

            return $needle === ''
                || str_contains(mb_strtolower(SectionReader::name($item)), $needle)
                || str_contains(mb_strtolower(SectionReader::path($item)), $needle)
                || str_contains(mb_strtolower(SectionReader::file($item)), $needle);
        }));

        if ($query->sort !== null) {
            $items = $this->sorted($items, $query->sort, $query->dir);
        }

        $perPage = max(1, $query->perPage);
        $lastPage = max(1, (int) ceil(count($items) / $perPage));
        $page = min($lastPage, max(1, $query->page));

        return new Page(array_slice($items, ($page - 1) * $perPage, $perPage), count($items), $page, $perPage);
    }

    // Health --------------------------------------------------------------

    public function orphans(): Orphans
    {
        $index = $this->index();

        return new Orphans(
            array_values(array_filter(
                $index->events(),
                static fn ($e): bool => $e->handledBy === [] && $e->dispatchedFrom === [],
            )),
            array_values(array_filter(
                $index->listeners(),
                static fn ($l): bool => $l->handles === [],
            )),
        );
    }

    /** @return list<UnresolvedDispatch> */
    public function unresolvedDispatches(): array
    {
        return $this->index()->unresolvedDispatches();
    }

    public function dashboard(int $topFanOut = 5): Dashboard
    {
        $index = $this->index();

        $counts = [];
        foreach (SectionRegistry::statsNames() as $name) {
            $counts[$name] = count($index->sections[$name] ?? []);
        }

        $orphans = $this->orphans();

        return new Dashboard(
            $this->meta(),
            $counts,
            count($orphans->events),
            count($orphans->idleListeners),
            count($index->unresolvedDispatches()),
            $this->fanOut($index, $topFanOut),
        );
    }

    /** @return list<SearchHit> */
    public function search(string $term, int $limit = 20): array
    {
        return (new Searcher($this->index()))->search($term, $limit);
    }

    public function meta(): IndexMeta
    {
        $index = $this->index();

        return new IndexMeta($index->loomVersion, $index->scannedAt, $index->laravelVersion);
    }

    // Internals -----------------------------------------------------------

    private function index(): Index
    {
        return $this->source->index();
    }

    private function eventImpact(Index $index, string $fqcn, ChangeKind $kind): ImpactReport
    {
        $handlers = array_map(
            static fn ($handler): HandlerRef => new HandlerRef($handler->listener, $handler->method, HandlerKind::LISTENER),
            $index->handlersOf($fqcn),
        );

        foreach ($index->closureListeners() as $closure) {
            if ($closure->event === $fqcn) {
                $handlers[] = new HandlerRef($closure->file.':'.$closure->line, 'closure', HandlerKind::CLOSURE);
            }
        }

        return new ImpactReport(
            $fqcn,
            $kind,
            ImpactEntity::EVENT,
            dispatchers: $index->dispatchersOf($fqcn),
            handlers: $handlers,
            downstream: (new ChainWalker($index))->chain($fqcn, ChainDepth::DEFAULT),
            notes: [
                $kind === ChangeKind::RENAME ? ImpactNote::RENAME_TOUCHES_ALL : ImpactNote::REMOVE_ORPHANS_HANDLERS,
                ImpactNote::DYNAMIC_DISPATCH_BLIND_SPOT,
            ],
        );
    }

    /** @return list<FanOut> */
    private function fanOut(Index $index, int $limit): array
    {
        if ($limit < 1) {
            return [];
        }

        $closureCounts = [];
        foreach ($index->closureListeners() as $closure) {
            $closureCounts[$closure->event] = ($closureCounts[$closure->event] ?? 0) + 1;
        }

        $candidates = [];
        foreach ($index->events() as $event) {
            $handlers = count($event->handledBy) + ($closureCounts[$event->fqcn] ?? 0);
            if ($handlers > 0) {
                $candidates[] = ['event' => $event, 'handlers' => $handlers];
            }
        }

        usort($candidates, static fn (array $a, array $b): int => [$b['handlers'], $a['event']->fqcn] <=> [$a['handlers'], $b['event']->fqcn]);

        $walker = new ChainWalker($index);
        $ranked = [];
        foreach (array_slice($candidates, 0, $limit) as $candidate) {
            $event = $candidate['event'];
            $reach = count($walker->chain($event->fqcn, ChainDepth::DEFAULT)->eventsReached) - 1;
            $ranked[] = new FanOut($event->fqcn, $candidate['handlers'], count($event->dispatchedFrom), $reach);
        }

        usort($ranked, static fn (FanOut $a, FanOut $b): int => [$b->handlerCount, $b->downstreamReach, $a->event]
            <=> [$a->handlerCount, $a->downstreamReach, $b->event]);

        return $ranked;
    }

    /**
     * @param  list<object>  $items
     * @return list<object>
     */
    private function sorted(array $items, SortField $field, SortDirection $dir): array
    {
        $key = static fn (object $item): string|int => match ($field) {
            SortField::NAME => strtolower(SectionReader::name($item)),
            SortField::URI => strtolower(SectionReader::path($item)),
            SortField::FILE => strtolower(SectionReader::file($item)),
            SortField::HANDLER_COUNT => SectionReader::handlerCount($item),
            SortField::DISPATCH_COUNT => SectionReader::dispatchCount($item),
        };

        usort($items, static function (object $a, object $b) use ($key, $dir): int {
            $cmp = $key($a) <=> $key($b);

            return $dir === SortDirection::DESC ? -$cmp : $cmp;
        });

        return $items;
    }
}
