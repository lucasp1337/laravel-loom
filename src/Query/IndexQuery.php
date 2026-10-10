<?php

declare(strict_types=1);

namespace Lucasp\Loom\Query;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
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
    public function __construct(private readonly IndexSource $source) {}

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

        $listeners = array_values(Arr::map($index->handlersOf($eventFqcn), function ($handler) use ($index): ListenerHandler {
            $listener = $index->findListener($handler->listener);

            return new ListenerHandler($handler->listener, $handler->method, $listener !== null && $listener->queued);
        }));

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
            : array_values(Arr::map($listener->handles, static fn ($handle): string => $handle->event));

        $closureEvents = [];
        foreach ($index->closureListeners() as $closure) {
            $closureEvents[$closure->event] = true;
        }

        // An event whose only handler is this class is left unhandled by removing it.
        $orphaned = [];
        foreach ($handles as $eventFqcn) {
            $others = Arr::where($index->handlersOf($eventFqcn), static fn ($handler): bool => $handler->listener !== $fqcn);
            if ($others === [] && ! isset($closureEvents[$eventFqcn])) {
                $orphaned[] = $eventFqcn;
            }
        }

        $matched = $listener ?? $job;
        $dispatches = array_values(Arr::map($matched->dispatches, DispatchRef::fromDispatch(...)));

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
                Arr::exists($index->sections, $section->value),
                $descriptor['listed'],
                EntityKind::forSection($section),
            );
        }

        return $out;
    }

    public function list(Sections $section, ?SectionQuery $query = null): Page
    {
        $query ??= new SectionQuery;
        $items = SectionReader::items($this->index(), $section);

        $needle = $query->search === null ? '' : Str::lower(trim($query->search));
        $items = array_values(Arr::where($items, function (object $item) use ($query, $needle): bool {
            foreach ($query->filters as $property => $expected) {
                if (SectionReader::comparable($item, $property) !== SectionReader::normalise($expected)) {
                    return false;
                }
            }

            return $needle === ''
                || Str::contains(Str::lower(SectionReader::name($item)), $needle)
                || Str::contains(Str::lower(SectionReader::path($item)), $needle)
                || Str::contains(Str::lower(SectionReader::file($item)), $needle);
        }));

        if ($query->sort !== null) {
            $items = $this->sorted($items, $query->sort, $query->dir);
        }

        $perPage = max(1, $query->perPage);
        $lastPage = max(1, (int) ceil(count($items) / $perPage));
        $page = min($lastPage, max(1, $query->page));

        return new Page(array_values(collect($items)->slice(($page - 1) * $perPage, $perPage)->all()), count($items), $page, $perPage);
    }

    // Health --------------------------------------------------------------

    public function orphans(): Orphans
    {
        $index = $this->index();

        return new Orphans(
            array_values(Arr::where($index->events(), static fn ($e): bool => $e->handledBy === [] && $e->dispatchedFrom === [])),
            array_values(Arr::where($index->listeners(), static fn ($l): bool => $l->handles === [])),
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

        $stats = [];
        foreach (SectionRegistry::names() as $name) {
            $stats[$name] = count($index->sections[$name] ?? []);
        }

        $orphans = $this->orphans();

        return new Dashboard(
            $this->meta(),
            $stats,
            count($orphans->orphanEvents),
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
        $handlers = array_values(Arr::map($index->handlersOf($fqcn), static fn ($handler): HandlerRef => new HandlerRef($handler->listener.'::'.$handler->method, HandlerKind::LISTENER)));

        foreach ($index->closureListeners() as $closure) {
            if ($closure->event === $fqcn) {
                $handlers[] = new HandlerRef($closure->file.':'.$closure->line, HandlerKind::CLOSURE);
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

        $candidates = array_values(collect($candidates)->sort(static fn (array $a, array $b): int => [$b['handlers'], $a['event']->fqcn] <=> [$a['handlers'], $b['event']->fqcn])->all());

        $walker = new ChainWalker($index);
        $ranked = [];
        foreach (collect($candidates)->slice(0, $limit)->all() as $candidate) {
            $event = $candidate['event'];
            $reach = count($walker->chain($event->fqcn, ChainDepth::DEFAULT)->eventsReached) - 1;
            $ranked[] = new FanOut($event->fqcn, $candidate['handlers'], count($event->dispatchedFrom), $reach);
        }

        $ranked = array_values(collect($ranked)->sort(static fn (FanOut $a, FanOut $b): int => [$b->handlerCount, $b->downstreamReach, $a->event]
            <=> [$a->handlerCount, $a->downstreamReach, $b->event])->all());

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

        // Ties fall back to name then file, always ascending, so the order never depends on input order.
        $items = array_values(collect($items)->sort(static function (object $a, object $b) use ($key, $dir): int {
            $cmp = $key($a) <=> $key($b);
            if ($dir === SortDirection::DESC) {
                $cmp = -$cmp;
            }

            return $cmp !== 0
                ? $cmp
                : [strtolower(SectionReader::name($a)), SectionReader::file($a)] <=> [strtolower(SectionReader::name($b)), SectionReader::file($b)];
        })->all());

        return $items;
    }
}
