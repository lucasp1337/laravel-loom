<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Lucasp\Loom\Contracts\Scanner;
use Lucasp\Loom\Dto\ClosureListenerEntry;
use Lucasp\Loom\Dto\ClosureListenerRecord;
use Lucasp\Loom\Dto\EventDiscoveryFact;
use Lucasp\Loom\Dto\ListenerEntry;
use Lucasp\Loom\Dto\ListenerHandle;
use Lucasp\Loom\Dto\ListenerLocation;
use Lucasp\Loom\Dto\ListenerPair;
use Lucasp\Loom\Dto\UnresolvedDispatchEntry;
use Lucasp\Loom\Dto\UnresolvedDispatchRecord;
use Lucasp\Loom\Index\ListenerRegistration;
use Lucasp\Loom\Scanners\Visitors\EventDiscoveryVisitor;
use Lucasp\Loom\Scanners\Visitors\EventListenCallVisitor;
use Lucasp\Loom\Scanners\Visitors\EventSubscribeCallVisitor;
use Lucasp\Loom\Scanners\Visitors\ListenArrayVisitor;
use Lucasp\Loom\Scanners\Visitors\ListenerClassVisitor;
use Lucasp\Loom\Scanners\Visitors\SubscribeArrayVisitor;
use Lucasp\Loom\Scanners\Visitors\SubscriberClassVisitor;
use Lucasp\Loom\Support\AppPath;
use Lucasp\Loom\Support\Ast\AstWalker;
use Lucasp\Loom\Support\ClassHierarchyResolver;
use Lucasp\Loom\Support\EventDiscovery;
use Lucasp\Loom\Support\EventDiscoveryResolver;
use Lucasp\Loom\Support\EventDiscoveryStatus;
use Lucasp\Loom\Support\LaravelClasses;
use Lucasp\Loom\Support\PrimitiveDirectory;
use Lucasp\Loom\Support\Psr4ClassLocator;
use Lucasp\Loom\Support\ScannerFilesystem;
use Lucasp\Loom\Support\ScanScope;

/**
 * Discovers event listeners from auto-discovery (app/Listeners/),
 * $listen arrays, Event::listen() calls, and $subscribe / Event::subscribe.
 * Closure and arrow-function registrations go to `closure_listeners[]`.
 *
 * Paths, merged by listener FQCN; the winning `registration` is
 * `subscriber`, then `listen_array`, `event_listen_call`, `auto_discovered`:
 * 1. Auto-discovery, only when the app runs it ({@see EventDiscoveryResolver}:
 *    on with `Application::configure()`, off with `withEvents(false)` or
 *    `disableEventDiscovery()`, off in a legacy bootstrap unless a provider's
 *    `shouldDiscoverEvents()` returns true). When the switch cannot be read the
 *    class is listed as `discovery_unverified`; when discovery is off it is
 *    listed only if registered explicitly. Mirrors Laravel's DiscoverEvents: an
 *    instantiable class under Listeners/ (or the directories the app set) with a public `handle*` or `__invoke` method taking a
 *    first parameter, declared, inherited or trait-provided (`as` renames and
 *    `insteadof` applied). The events are the class types of that parameter
 *    (nullable and union types give each class; builtin and intersection
 *    members give none). A method with no class type still lists the listener
 *    with `handles: []`; no parameter, an abstract class, or a method made
 *    non-public gives nothing.
 * 2. `$listen` on any class named `EventServiceProvider` or extending the
 *    framework one, anywhere under the scan paths.
 * 3. `listen(...)` calls on the dispatcher, from any class
 *    ({@see EventListenCallVisitor}). An array of events expands to one
 *    registration per event.
 * 4. Subscribers named in `$subscribe` or `Event::subscribe(...)`
 *    ({@see SubscriberClassVisitor}).
 *
 * Registrations (2-4) are also read from the providers that
 * `bootstrap/providers.php` and `config/app.php` list, inside or outside the
 * scan paths.
 *
 * Listener values are `Foo::class`, `'Fqcn@method'`, `Foo::class.'@method'`,
 * tuples and callables; a string event key is kept as written (`eloquent.*`
 * names are model events, read by ObserverScanner). A registration whose event
 * or listener cannot be read is emitted in `unresolved_dispatches`.
 *
 * `method` is `handle` when a registration names none, and `__invoke` when
 * the class lacks the named method but has `__invoke` (the dispatcher's
 * fallback). `after_commit` follows `ShouldHandleEventsAfterCommit` (sync),
 * `ShouldQueueAfterCommit` (queued) and a literal `$afterCommit`. A listener named in a
 * registration but located outside Listeners/ is found through PSR-4; if its
 * file cannot be located it is dropped, since `file` and `line` are required.
 * `handles[]` is deduplicated on (event, method), so one listener can handle
 * different events, or one event, through several methods. `queued` is true
 * when the class transitively implements `ShouldQueue`, through parents and
 * traits; a vendor parent is an opaque leaf, so `ShouldQueue` inherited from a
 * package is not seen.
 *
 * @internal
 */
final class ListenerScanner implements Scanner
{
    use ScannerFilesystem;

    private AstWalker $walker;

    private Psr4ClassLocator $locator;

    /** @var list<EventDiscoveryFact> */
    private array $discoveryFacts = [];

    /** @var list<UnresolvedDispatchEntry> */
    private array $unresolved = [];

    public function __construct(?AstWalker $walker = null, ?Psr4ClassLocator $locator = null, ?ScanScope $scope = null)
    {
        $this->walker = $walker ?? new AstWalker;
        $this->locator = $locator ?? new Psr4ClassLocator;
        $this->scope = $scope;
    }

    /**
     * @return array{listeners: list<ListenerEntry>, closure_listeners: list<ClosureListenerEntry>, unresolved_dispatches: list<UnresolvedDispatchEntry>}
     */
    public function scan(string $appRoot): array
    {
        $this->discoveryFacts = [];
        $this->unresolved = [];
        $resolver = new ClassHierarchyResolver($appRoot, $this->walker, $this->scope());

        // The listen-array walk also gathers the discovery switches that auto-discovery depends on.
        [$listenArrayPairs, $listenArrayClosures] = $this->discoverFromListenArray($appRoot);
        $autoDiscovered = $this->discoverFromAutoDiscovery($appRoot, $resolver, $this->resolveDiscovery($appRoot));
        [$eventListenPairs, $eventListenClosures] = $this->discoverFromEventListenCalls($appRoot);
        $subscriberFqcns = $this->discoverSubscriberFqcns($appRoot);
        [$subscribers, $subscriberClosures, $subscriberForeignPairs] = $this->resolveSubscribers($appRoot, $subscriberFqcns);

        $merged = $this->merge($appRoot, $autoDiscovered, $listenArrayPairs, $eventListenPairs, $subscribers, $subscriberForeignPairs);

        return [
            'listeners' => $this->emit($merged, $resolver),
            'closure_listeners' => $this->buildClosureListeners(
                $listenArrayClosures,
                $eventListenClosures,
                $subscriberClosures,
            ),
            'unresolved_dispatches' => $this->unresolved,
        ];
    }

    private function resolveDiscovery(string $appRoot): EventDiscovery
    {
        $bootstrap = AppPath::join($appRoot, 'bootstrap/app.php');
        $hasBootstrap = is_file($bootstrap);
        $facts = [];
        if ($hasBootstrap) {
            $visitor = new EventDiscoveryVisitor;
            $this->walker->walk($bootstrap, [$visitor]);
            $facts = $this->anchored($visitor->getFacts(), 'bootstrap');
        }

        return EventDiscoveryResolver::resolve($hasBootstrap, $facts, $this->discoveryFacts);
    }

    /**
     * @param  list<EventDiscoveryFact>  $facts
     * @return list<EventDiscoveryFact>
     */
    private function anchored(array $facts, string $directory): array
    {
        return array_values(Arr::map($facts, fn (EventDiscoveryFact $fact): EventDiscoveryFact => $fact->paths === [] || $fact->paths === null
            ? $fact
            : new EventDiscoveryFact(
                $fact->kind,
                $fact->value,
                $this->anchorPaths($fact->paths, $directory),
                $fact->dynamic,
            )));
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>|null null when a path leaves the app root
     */
    private function anchorPaths(array $paths, string $directory): ?array
    {
        $anchored = [];
        foreach ($paths as $path) {
            $resolved = EventDiscoveryResolver::anchor($path, $directory);
            if ($resolved === null) {
                return null;
            }
            $anchored[] = $resolved;
        }

        return $anchored;
    }

    /**
     * @param  list<UnresolvedDispatchRecord>  $records
     */
    private function collectUnresolved(string $relative, array $records): void
    {
        foreach ($records as $record) {
            $this->unresolved[] = new UnresolvedDispatchEntry(
                file: $relative,
                line: $record->line,
                expression: $record->expression,
                reason: $record->reason,
            );
        }
    }

    /**
     * @return array<string, ListenerLocation>
     */
    private function discoverFromAutoDiscovery(string $appRoot, ClassHierarchyResolver $resolver, EventDiscovery $discovery): array
    {
        // With discovery off, only explicit registrations make a listener.
        if ($discovery->status === EventDiscoveryStatus::DISABLED) {
            return [];
        }

        $registration = $discovery->status === EventDiscoveryStatus::ENABLED
            ? ListenerRegistration::AUTO_DISCOVERED
            : ListenerRegistration::DISCOVERY_UNVERIFIED;

        $visitor = new ListenerClassVisitor;
        $results = [];

        foreach ($this->discoveryFiles($appRoot, $discovery) as $file) {
            $this->walker->walk($file->getPathname(), [$visitor]);

            foreach ($visitor->getClasses() as $class) {
                $handles = $this->discoverHandles($resolver, $class->fqcn);
                if ($handles === null) {
                    continue;
                }

                $location = new ListenerLocation(
                    file: $this->relativePath($appRoot, $file->getPathname()),
                    line: $class->line,
                    queued: $class->queued,
                    registration: $registration,
                );
                foreach ($handles as $handle) {
                    $location->handles[$handle->event.'::'.$handle->method] = $handle;
                }
                $results[$class->fqcn] = $location;
            }
        }

        return $results;
    }

    /**
     * Files under `Listeners/` in the scan directories, or under the
     * directories the app configured for discovery.
     *
     * @return iterable<\SplFileInfo>
     */
    private function discoveryFiles(string $appRoot, EventDiscovery $discovery): iterable
    {
        if ($discovery->directories === null) {
            return $this->scanFiles($appRoot, PrimitiveDirectory::LISTENERS);
        }

        return collect($this->scanFiles($appRoot))->filter(function (\SplFileInfo $file) use ($appRoot, $discovery): bool {
            $relative = $this->relativePath($appRoot, $file->getPathname());

            return collect($discovery->directories)->contains(fn (string $directory): bool => Str::startsWith($relative, $directory.'/'));
        });
    }

    /**
     * Mirrors Laravel's listener discovery: an instantiable class, and every
     * public `handle*` or `__invoke` method with a first parameter, whether
     * declared, inherited or provided by a trait. Events come from that
     * parameter's class types. Null when the class has no such method.
     *
     * @return list<ListenerHandle>|null
     */
    private function discoverHandles(ClassHierarchyResolver $resolver, string $fqcn): ?array
    {
        if (! $resolver->isInstantiable($fqcn)) {
            return null;
        }

        $handles = [];
        $matched = false;
        foreach ($resolver->effectiveMethods($fqcn) as $method) {
            $isHandler = Str::startsWith($method->name, 'handle') || $method->name === '__invoke';
            if (! $isHandler || ! $method->isPublic() || $method->isAbstract || ! $method->hasParameters) {
                continue;
            }

            $matched = true;
            foreach ($resolver->firstParameterClasses($method) as $event) {
                $handles[] = new ListenerHandle(event: $event, method: $method->name);
            }
        }

        return $matched ? $handles : null;
    }

    /**
     * @return array{0: list<ListenerPair>, 1: list<ClosureListenerRecord>}
     */
    private function discoverFromListenArray(string $appRoot): array
    {
        $visitor = new ListenArrayVisitor;
        $discoveryVisitor = new EventDiscoveryVisitor;
        $pairs = [];
        $closures = [];

        foreach ($this->registrationFiles($appRoot, $this->walker) as $file) {
            $this->walker->walk($file->getPathname(), [$visitor, $discoveryVisitor]);
            $relative = $this->relativePath($appRoot, $file->getPathname());
            $this->collectUnresolved($relative, $visitor->getUnresolved());
            $this->discoveryFacts = [...$this->discoveryFacts, ...$this->anchored($discoveryVisitor->getFacts(), Str::beforeLast($relative, '/'))];
            foreach ($visitor->getPairs() as $pair) {
                $pairs[] = $pair;
            }
            foreach ($visitor->getClosurePairs() as $closure) {
                $closures[] = new ClosureListenerRecord(
                    event: $closure->event,
                    file: $relative,
                    line: $closure->line,
                    endLine: $closure->endLine,
                    registration: $closure->registration,
                );
            }
        }

        return [$pairs, $closures];
    }

    /**
     * @return array{0: list<ListenerPair>, 1: list<ClosureListenerRecord>}
     */
    private function discoverFromEventListenCalls(string $appRoot): array
    {
        $visitor = new EventListenCallVisitor;
        $pairs = [];
        $closures = [];

        foreach ($this->registrationFiles($appRoot, $this->walker) as $file) {
            $this->walker->walk($file->getPathname(), [$visitor]);
            $relative = $this->relativePath($appRoot, $file->getPathname());
            $this->collectUnresolved($relative, $visitor->getUnresolved());
            foreach ($visitor->getPairs() as $pair) {
                $pairs[] = $pair;
            }
            foreach ($visitor->getClosurePairs() as $closure) {
                $closures[] = new ClosureListenerRecord(
                    event: $closure->event,
                    file: $relative,
                    line: $closure->line,
                    endLine: $closure->endLine,
                    registration: $closure->registration,
                );
            }
        }

        return [$pairs, $closures];
    }

    /**
     * @return list<string>
     */
    private function discoverSubscriberFqcns(string $appRoot): array
    {
        $arrayVisitor = new SubscribeArrayVisitor;
        $callVisitor = new EventSubscribeCallVisitor;

        $seen = [];

        foreach ($this->registrationFiles($appRoot, $this->walker) as $file) {
            $this->walker->walk($file->getPathname(), [$arrayVisitor, $callVisitor]);
            $relative = $this->relativePath($appRoot, $file->getPathname());
            $this->collectUnresolved($relative, $arrayVisitor->getUnresolved());
            $this->collectUnresolved($relative, $callVisitor->getUnresolved());
            foreach ($arrayVisitor->getSubscribers() as $fqcn) {
                $seen[$fqcn] = true;
            }
            foreach ($callVisitor->getSubscribers() as $fqcn) {
                $seen[$fqcn] = true;
            }
        }

        return array_keys($seen);
    }

    /**
     * @param  list<string>  $fqcns
     * @return array{0: array<string, ListenerLocation>, 1: list<ClosureListenerRecord>, 2: list<ListenerPair>}
     */
    private function resolveSubscribers(string $appRoot, array $fqcns): array
    {
        $result = [];
        $closures = [];
        $foreignPairs = [];

        foreach ($fqcns as $fqcn) {
            $absolute = $this->locator->locate($appRoot, $fqcn);
            if ($absolute === null || ! $this->scope()->admits($appRoot, $absolute)) {
                continue;
            }

            $visitor = new SubscriberClassVisitor;
            $this->walker->walk($absolute, [$visitor]);

            $relative = $this->relativePath($appRoot, $absolute);
            $this->collectUnresolved($relative, $visitor->getUnresolved());

            foreach ($visitor->getClasses() as $class) {
                if ($class->fqcn !== $fqcn) {
                    continue;
                }
                $location = new ListenerLocation(
                    file: $relative,
                    line: $class->line,
                    queued: $class->queued,
                    registration: ListenerRegistration::SUBSCRIBER,
                );
                foreach ($class->handles as $handle) {
                    $location->handles[$handle->event.'::'.$handle->method] = $handle;
                }
                $result[$fqcn] = $location;

                foreach ($class->closureHandles as $closure) {
                    $closures[] = new ClosureListenerRecord(
                        event: $closure->event,
                        file: $relative,
                        line: $closure->line,
                        endLine: $closure->endLine,
                        registration: ListenerRegistration::SUBSCRIBER,
                    );
                }
                foreach ($class->foreignPairs as $pair) {
                    $foreignPairs[] = $pair;
                }
                break;
            }
        }

        return [$result, $closures, $foreignPairs];
    }

    /**
     * Precedence: subscriber > listen_array > event_listen_call > auto_discovered.
     * `handles` is the union across sources.
     *
     * @param  array<string, ListenerLocation>  $autoDiscovered
     * @param  list<ListenerPair>  $listenArrayPairs
     * @param  list<ListenerPair>  $eventListenPairs
     * @param  array<string, ListenerLocation>  $subscribers
     * @param  list<ListenerPair>  $subscriberForeignPairs
     * @return array<string, ListenerLocation>
     */
    private function merge(string $appRoot, array $autoDiscovered, array $listenArrayPairs, array $eventListenPairs, array $subscribers, array $subscriberForeignPairs = []): array
    {
        $acc = $autoDiscovered;

        foreach ($eventListenPairs as $pair) {
            $this->applyPair($acc, $pair, ListenerRegistration::EVENT_LISTEN_CALL);
        }

        foreach ($listenArrayPairs as $pair) {
            $this->applyPair($acc, $pair, ListenerRegistration::LISTEN_ARRAY);
        }

        foreach ($subscribers as $fqcn => $sub) {
            if (! isset($acc[$fqcn])) {
                $acc[$fqcn] = $sub;
            } else {
                // Subscriber precedence wins — point at the subscriber class.
                $acc[$fqcn]->file = $sub->file;
                $acc[$fqcn]->line = $sub->line;
                $acc[$fqcn]->queued = $sub->queued;
                if ($this->precedence(ListenerRegistration::SUBSCRIBER) > $this->precedence($acc[$fqcn]->registration)) {
                    $acc[$fqcn]->registration = ListenerRegistration::SUBSCRIBER;
                }
                foreach ($sub->handles as $key => $handle) {
                    $acc[$fqcn]->handles[$key] = $handle;
                }
            }
        }

        // Listeners wired imperatively from inside subscribe() bodies.
        foreach ($subscriberForeignPairs as $pair) {
            $this->applyPair($acc, $pair, ListenerRegistration::SUBSCRIBER);
        }

        $result = [];
        foreach ($acc as $fqcn => $loc) {
            // $listen/Event::listen-only entries lack a file hit; PSR-4 guess or drop.
            if ($loc->file === null || $loc->line === null) {
                $located = $this->locateByPsr4Guess($appRoot, $fqcn);
                if ($located === null) {
                    continue;
                }
                $loc->file = $located->file;
                $loc->line = $located->line;
                $loc->queued = $located->queued;
            }

            $result[$fqcn] = $loc;
        }

        return $result;
    }

    /**
     * @param  array<string, ListenerLocation>  $acc
     */
    private function applyPair(array &$acc, ListenerPair $pair, ListenerRegistration $registration): void
    {
        $fqcn = $pair->listener;

        if (! isset($acc[$fqcn])) {
            $acc[$fqcn] = new ListenerLocation(file: null, line: null, queued: false, registration: $registration);
        }

        $key = $pair->event.'::'.$pair->method;
        $acc[$fqcn]->handles[$key] = new ListenerHandle(event: $pair->event, method: $pair->method);

        if ($this->precedence($registration) > $this->precedence($acc[$fqcn]->registration)) {
            $acc[$fqcn]->registration = $registration;
        }
    }

    private function precedence(ListenerRegistration $registration): int
    {
        return match ($registration) {
            ListenerRegistration::SUBSCRIBER => 4,
            ListenerRegistration::MODEL_HOOK,
            ListenerRegistration::DISCOVERY_UNVERIFIED => 0,
            ListenerRegistration::LISTEN_ARRAY => 3,
            ListenerRegistration::EVENT_LISTEN_CALL => 2,
            ListenerRegistration::AUTO_DISCOVERED => 1,
        };
    }

    private function locateByPsr4Guess(string $appRoot, string $fqcn): ?ListenerLocation
    {
        $absolute = $this->locator->locate($appRoot, $fqcn);
        if ($absolute === null || ! $this->scope()->admits($appRoot, $absolute)) {
            return null;
        }

        $visitor = new ListenerClassVisitor;
        $this->walker->walk($absolute, [$visitor]);

        foreach ($visitor->getClasses() as $class) {
            if ($class->fqcn !== $fqcn) {
                continue;
            }

            return new ListenerLocation(
                file: $this->relativePath($appRoot, $absolute),
                line: $class->line,
                queued: $class->queued,
                registration: ListenerRegistration::AUTO_DISCOVERED,
            );
        }

        return null;
    }

    /**
     * @param  array<string, ListenerLocation>  $merged
     * @return list<ListenerEntry>
     */
    private function emit(array $merged, ClassHierarchyResolver $resolver): array
    {
        $merged = collect($merged)->sortKeys()->all();

        $entries = [];
        foreach ($merged as $fqcn => $loc) {
            $handles = array_values(collect($loc->handles)
                ->map(fn (ListenerHandle $handle): ListenerHandle => new ListenerHandle(event: $handle->event, method: $resolver->dispatchedMethod($fqcn, $handle->method)))
                ->unique(fn (ListenerHandle $handle): string => $handle->event.'::'.$handle->method)
                ->sort(fn (ListenerHandle $a, ListenerHandle $b): int => [$a->event, $a->method] <=> [$b->event, $b->method])
                ->all());
            $queued = $resolver->isQueued($fqcn);

            // file/line are non-null at emit time — guaranteed by merge()'s PSR-4 guess.
            $entries[] = new ListenerEntry(
                fqcn: $fqcn,
                file: (string) $loc->file,
                line: (int) $loc->line,
                handles: $handles,
                registration: $loc->registration,
                queued: $queued,
                afterCommit: $this->runsAfterCommit($resolver, $fqcn, $queued),
            );
        }

        return $entries;
    }

    /**
     * Whether the dispatcher defers the listener until the open transaction
     * commits: a sync listener through `ShouldHandleEventsAfterCommit`, a
     * queued one through `ShouldQueueAfterCommit`, either through a literal
     * `$afterCommit = true` (only the property default for a queued one, which
     * is instantiated without its constructor).
     */
    private function runsAfterCommit(ClassHierarchyResolver $resolver, string $fqcn, bool $queued): bool
    {
        return $resolver->queueConfig($fqcn, interfaceWinsOverFalse: true, readsConstructor: ! $queued)->afterCommit === true
            || (! $queued && $resolver->implementsInterface($fqcn, LaravelClasses::SHOULD_HANDLE_EVENTS_AFTER_COMMIT->value));
    }

    /**
     * Dedup by (event, file, line, registration); sort by (event, file, line).
     *
     * @param  list<ClosureListenerRecord>  $listenArrayClosures
     * @param  list<ClosureListenerRecord>  $eventListenClosures
     * @param  list<ClosureListenerRecord>  $subscriberClosures
     * @return list<ClosureListenerEntry>
     */
    private function buildClosureListeners(
        array $listenArrayClosures,
        array $eventListenClosures,
        array $subscriberClosures,
    ): array {
        /** @var array<string, ClosureListenerRecord> $seen */
        $seen = [];

        foreach ([$listenArrayClosures, $eventListenClosures, $subscriberClosures] as $bucket) {
            foreach ($bucket as $entry) {
                $key = $entry->event.'|'.$entry->file.'|'.$entry->line.'|'.$entry->registration->value;
                $seen[$key] = $entry;
            }
        }

        $records = array_values($seen);
        $records = array_values(collect($records)->sort(fn (ClosureListenerRecord $a, ClosureListenerRecord $b): int => [$a->event, $a->file, $a->line] <=> [$b->event, $b->file, $b->line])->all());

        // Cross-link populates dispatches[]; emitting empty here matches the schema.
        return array_values(Arr::map($records, fn (ClosureListenerRecord $r): ClosureListenerEntry => new ClosureListenerEntry(
            event: $r->event,
            file: $r->file,
            line: $r->line,
            endLine: $r->endLine,
            registration: $r->registration,
            queued: false,
        )));
    }
}
