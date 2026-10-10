<?php

declare(strict_types=1);

use Lucasp\Loom\Index\Index;
use Lucasp\Loom\Index\IndexLoader;
use Lucasp\Loom\Index\Model\Event;
use Lucasp\Loom\Index\Model\Route;
use Lucasp\Loom\Index\SectionRegistry;
use Lucasp\Loom\Index\Sections;
use Lucasp\Loom\Query\ChainDepth;
use Lucasp\Loom\Query\ChangeKind;
use Lucasp\Loom\Query\Dto\Dashboard;
use Lucasp\Loom\Query\Dto\FanOut;
use Lucasp\Loom\Query\Dto\ImpactReport;
use Lucasp\Loom\Query\Dto\IndexMeta;
use Lucasp\Loom\Query\Dto\Orphans;
use Lucasp\Loom\Query\Dto\Page;
use Lucasp\Loom\Query\Dto\RouteChain;
use Lucasp\Loom\Query\Dto\SearchHit;
use Lucasp\Loom\Query\Dto\SectionInfo;
use Lucasp\Loom\Query\Dto\SectionQuery;
use Lucasp\Loom\Query\EntityKind;
use Lucasp\Loom\Query\HandlerKind;
use Lucasp\Loom\Query\ImpactEntity;
use Lucasp\Loom\Query\ImpactNote;
use Lucasp\Loom\Query\IndexQuery;
use Lucasp\Loom\Query\IndexSource;
use Lucasp\Loom\Query\SortDirection;
use Lucasp\Loom\Query\SortField;

/** @param  array<string, mixed>  $overrides */
function queryFor(array $overrides = []): IndexQuery
{
    /** @var array<string, mixed> $data */
    $data = array_merge(require __DIR__.'/../../Fixtures/query-index.php', $overrides);
    $index = (new IndexLoader)->fromArray($data);

    return new IndexQuery(new class($index) implements IndexSource
    {
        public function __construct(private readonly Index $index) {}

        public function index(): Index
        {
            return $this->index;
        }

        public function path(): string
        {
            return 'memory';
        }

        public function isAvailable(): bool
        {
            return true;
        }
    });
}

$e = static fn (string $n): string => "App\\Events\\{$n}";

// eventChain ------------------------------------------------------------------

it('follows an event through handlers and their dispatched events', function () use ($e) {
    $chain = queryFor()->eventChain($e('OrderPlaced'));

    expect($chain->root)->toBe($e('OrderPlaced'))
        ->and($chain->depth)->toBe(3)
        ->and($chain->eventsReached)->toBe([$e('OrderPlaced'), $e('ReceiptSent'), $e('Ping')])
        ->and($chain->truncated)->toBeTrue()
        ->and($chain->edges[0]->handler)->toBe('App\\Listeners\\SendReceipt::handle')
        ->and($chain->edges[0]->handlerKind)->toBe(HandlerKind::LISTENER)
        ->and($chain->edges[0]->level)->toBe(0)
        ->and($chain->edges[0]->file)->toBe('app/Listeners/SendReceipt.php');
});

it('includes closure listeners as handlers', function () use ($e) {
    $chain = queryFor()->eventChain($e('ReceiptSent'), 1);

    $kinds = array_map(static fn ($edge) => $edge->handlerKind, $chain->edges);

    expect($kinds)->toBe([HandlerKind::LISTENER, HandlerKind::CLOSURE])
        ->and($chain->edges[1]->handler)->toBe('app/Providers/EventServiceProvider.php:30')
        ->and($chain->edges[1]->line)->toBe(30);
});

it('detects a cycle instead of looping and does not flag it truncated', function () use ($e) {
    $chain = queryFor()->eventChain($e('Ping'), 6);

    expect($chain->eventsReached)->toBe([$e('Ping'), $e('Pong')])
        ->and($chain->edges)->toHaveCount(2)
        ->and($chain->cycles)->toHaveCount(1)
        ->and($chain->cycles[0]->fromHandler)->toBe('App\\Listeners\\PongListener::handle')
        ->and($chain->cycles[0]->backToEvent)->toBe($e('Ping'))
        ->and($chain->truncated)->toBeFalse();
});

it('stops at the depth bound and reports truncation', function () use ($e) {
    $q = queryFor();

    $shallow = $q->eventChain($e('OrderPlaced'), 1);
    expect($shallow->eventsReached)->toBe([$e('OrderPlaced')])
        ->and($shallow->truncated)->toBeTrue();

    $two = $q->eventChain($e('OrderPlaced'), 2);
    expect($two->eventsReached)->toBe([$e('OrderPlaced'), $e('ReceiptSent')])
        ->and($two->truncated)->toBeTrue();

    $deep = $q->eventChain($e('OrderPlaced'), 6);
    expect($deep->truncated)->toBeFalse();
});

it('clamps depth to the allowed range', function () use ($e) {
    $q = queryFor();

    expect($q->eventChain($e('Ping'), 0)->depth)->toBe(ChainDepth::MIN)
        ->and($q->eventChain($e('Ping'), 99)->depth)->toBe(ChainDepth::MAX)
        ->and(ChainDepth::clamp(-5))->toBe(1);
});

it('returns an empty chain for unknown or unhandled events', function () use ($e) {
    $q = queryFor();

    expect($q->eventChain('App\\Nope')->edges)->toBe([])
        ->and($q->eventChain('App\\Nope')->eventsReached)->toBe(['App\\Nope'])
        ->and($q->eventChain($e('Lonely'))->truncated)->toBeFalse();
});

it('always emits cycles and truncated in the array shape', function () use ($e) {
    $chain = queryFor()->eventChain($e('Ping'));

    expect(array_keys($chain->toArray()))->toBe(['root', 'depth', 'edges', 'events_reached', 'cycles', 'truncated']);
});

it('reports diamonds as revisits, not cycles; ignores handlerless frontier for truncation', function () {
    $ev = static fn (string $n, array $h): array => ['id' => "E\\$n", 'fqcn' => "E\\$n", 'kind' => 'class', 'file' => "$n.php", 'line' => 1, 'handled_by' => $h, 'dispatched_from' => []];
    $h = static fn (string $n): array => ['listener' => "L\\$n", 'method' => 'handle'];
    $l = static fn (string $n, string $handles, array $targets): array => [
        'fqcn' => "L\\$n", 'file' => "$n.php", 'line' => 1, 'registration' => 'auto_discovered', 'queued' => false,
        'handles' => [['event' => "E\\$handles", 'method' => 'handle']],
        'dispatches' => array_map(static fn (string $t): array => ['target' => "E\\$t", 'kind' => 'event', 'confidence' => 'high', 'file' => "$n.php", 'line' => 2], $targets),
    ];

    $q = queryFor([
        'events' => [$ev('A', [$h('A')]), $ev('B', [$h('B')]), $ev('C', [$h('C')]), $ev('D', [])],
        'listeners' => [$l('A', 'A', ['B', 'C']), $l('B', 'B', ['D']), $l('C', 'C', ['D'])],
        'closure_listeners' => [],
    ]);

    expect($q->eventChain('E\\A', 6)->cycles)->toBe([])
        ->and($q->eventChain('E\\A', 2)->truncated)->toBeFalse();

    $ping = queryFor()->eventChain('App\\Events\\Ping', 6);
    expect($ping->cycles)->toHaveCount(1)
        ->and($ping->cycles[0]->backToEvent)->toBe('App\\Events\\Ping');
});

// eventsFromMethod / dispatchesFrom -------------------------------------------

it('resolves dispatches from Class::method, Class@method and bare Class', function () {
    $q = queryFor();
    $ctrl = 'App\\Http\\Controllers\\OrderController';

    expect($q->dispatchesFrom("{$ctrl}::store"))->toHaveCount(2)
        ->and($q->dispatchesFrom("{$ctrl}@store"))->toHaveCount(2)
        ->and($q->dispatchesFrom($ctrl))->toHaveCount(2)
        ->and($q->dispatchesFrom("{$ctrl}::other"))->toBe([])
        ->and($q->dispatchesFrom('App\\Nope'))->toBe([]);
});

it('dedupes dispatches on target and location', function () {
    $refs = queryFor()->dispatchesFrom('App\\Http\\Controllers\\OrderController::store');

    expect(array_map(static fn ($r) => $r->target, $refs))
        ->toBe(['App\\Events\\OrderPlaced', 'App\\Jobs\\SendMail']);
});

it('chains only the event dispatches of a method', function () use ($e) {
    $result = queryFor()->eventsFromMethod('App\\Http\\Controllers\\OrderController::store', 2);

    expect($result->dispatches)->toHaveCount(2)
        ->and($result->chains)->toHaveCount(1)
        ->and($result->chains[0]->root)->toBe($e('OrderPlaced'))
        ->and($result->chains[0]->depth)->toBe(2);
});

// handlersFor / dispatchSitesFor ----------------------------------------------

it('lists listener and closure handlers with their queued flags', function () use ($e) {
    $set = queryFor()->handlersFor($e('ReceiptSent'));

    expect($set->count())->toBe(2)
        ->and($set->listeners[0]->listener)->toBe('App\\Listeners\\ArchiveReceipt')
        ->and($set->listeners[0]->queued)->toBeFalse()
        ->and($set->closureListeners[0]->queued)->toBeTrue()
        ->and(queryFor()->handlersFor($e('Ping'))->listeners[0]->queued)->toBeTrue()
        ->and(queryFor()->handlersFor('App\\Nope')->count())->toBe(0);
});

it('lists dispatch sites for an event', function () use ($e) {
    $set = queryFor()->dispatchSitesFor($e('OrderPlaced'));

    expect($set->count())->toBe(1)
        ->and($set->sites[0]->line)->toBe(20)
        ->and(queryFor()->dispatchSitesFor($e('Lonely'))->count())->toBe(0);
});

// routeFor / routeChain -------------------------------------------------------

it('matches routes ignoring verb case and leading slashes', function () {
    $q = queryFor();

    expect($q->routeFor('post', '/orders'))->toBeInstanceOf(Route::class)
        ->and($q->routeFor('POST', 'orders')?->name)->toBe('orders.store')
        ->and($q->routeFor('get', 'ping')?->uri)->toBe('/ping')
        ->and($q->routeFor('GET', '/ping')?->name)->toBe('ping')
        ->and($q->routeFor('GET', 'orders'))->toBeNull()
        ->and($q->routeFor('DELETE', 'nope'))->toBeNull();
});

it('builds a route chain, treating a null controller method as __invoke', function () {
    $q = queryFor();

    $store = $q->routeChain('POST', 'orders');
    expect($store?->chain?->methodFqcn)->toBe('App\\Http\\Controllers\\OrderController::store');

    $invoke = $q->routeChain('GET', 'ping');
    // Pinned legacy quirk: the route stores a null method, but dispatches are
    // matched on the literal `__invoke`, so an invokable controller yields nothing.
    expect($invoke?->chain?->methodFqcn)->toBe('App\\Http\\Controllers\\PingController::__invoke')
        ->and($invoke?->chain?->dispatches)->toBe([]);

    $closure = $q->routeChain('GET', 'health');
    expect($closure)->not->toBeNull()
        ->and($closure?->chain)->toBeNull()
        ->and($q->routeChain('PUT', 'x'))->toBeNull();
});

// impactOfChange --------------------------------------------------------------

it('reports impact for an event', function () use ($e) {
    $report = queryFor()->impactOfChange($e('OrderPlaced'), ChangeKind::RENAME);

    expect($report->kind)->toBe(ImpactEntity::EVENT)
        ->and($report->dispatchers)->toHaveCount(1)
        ->and($report->handlers[0]->kind)->toBe(HandlerKind::LISTENER)
        ->and($report->downstream?->depth)->toBe(ChainDepth::DEFAULT)
        ->and($report->notes)->toBe([ImpactNote::RENAME_TOUCHES_ALL, ImpactNote::DYNAMIC_DISPATCH_BLIND_SPOT]);
});

it('lists closure handlers in an event impact report', function () use ($e) {
    $report = queryFor()->impactOfChange($e('ReceiptSent'));

    expect($report->handlers[1]->handler)->toBe('app/Providers/EventServiceProvider.php:30')
        ->and($report->handlers[1]->kind)->toBe(HandlerKind::CLOSURE)
        ->and($report->notes[0])->toBe(ImpactNote::REMOVE_ORPHANS_HANDLERS);
});

it('flags events a listener removal would orphan', function () use ($e) {
    $q = queryFor();

    $ping = $q->impactOfChange('App\\Listeners\\PingListener');
    expect($ping->kind)->toBe(ImpactEntity::LISTENER)
        ->and($ping->wouldOrphanEvents)->toBe([$e('Ping')])
        ->and($ping->notes[0])->toBe(ImpactNote::WOULD_ORPHAN_EVENTS);

    // A closure listener also handles ReceiptSent, so ArchiveReceipt is safe to drop.
    $archive = $q->impactOfChange('App\\Listeners\\ArchiveReceipt');
    expect($archive->wouldOrphanEvents)->toBe([])
        ->and($archive->notes[0])->toBe(ImpactNote::NO_ORPHANS);
});

it('reports impact for a job and for an unknown class', function () {
    $q = queryFor();

    $job = $q->impactOfChange('App\\Jobs\\SendMail');
    expect($job->kind)->toBe(ImpactEntity::JOB)
        ->and($job->handles)->toBe([])
        ->and($job->dispatches)->toHaveCount(1);

    $unknown = $q->impactOfChange('App\\Nope');
    expect($unknown->kind)->toBe(ImpactEntity::UNKNOWN)
        ->and($unknown->notes)->toBe([ImpactNote::UNKNOWN_FQCN])
        ->and($unknown->toArray()['downstream'])->toBeNull();
});

it('emits note codes when no renderer is given', function () {
    expect(queryFor()->impactOfChange('App\\Nope')->toArray()['notes'])->toBe(['unknown_fqcn']);
});

// orphans / unresolved / entity / meta ----------------------------------------

it('finds orphan events and idle listeners', function () {
    $orphans = queryFor()->orphans();

    expect(array_map(static fn (Event $x) => $x->fqcn, $orphans->orphanEvents))->toBe(['App\\Events\\Lonely'])
        ->and(array_map(static fn ($l) => $l->fqcn, $orphans->idleListeners))->toBe(['App\\Listeners\\Idle']);
});

it('returns unresolved dispatches', function () {
    $list = queryFor()->unresolvedDispatches();

    expect($list)->toHaveCount(1)
        ->and($list[0]->reason)->toBe('dynamic_class_name');
});

it('looks entities up by kind and fqcn', function () {
    $q = queryFor();

    expect($q->entity(EntityKind::EVENT, 'App\\Events\\Ping'))->toBeInstanceOf(Event::class)
        ->and($q->entity(EntityKind::JOB, 'App\\Jobs\\SendMail'))->not->toBeNull()
        ->and($q->entity(EntityKind::MAILABLE, 'App\\Mail\\Receipt'))->not->toBeNull()
        ->and($q->entity(EntityKind::NOTIFICATION, 'App\\Notifications\\Shipped'))->not->toBeNull()
        ->and($q->entity(EntityKind::OBSERVER, 'App\\Observers\\OrderObserver'))->not->toBeNull()
        ->and($q->entity(EntityKind::LISTENER, 'App\\Nope'))->toBeNull()
        ->and(EntityKind::forSection(Sections::ROUTES))->toBeNull()
        ->and(EntityKind::forSection(Sections::JOBS))->toBe(EntityKind::JOB);
});

it('exposes index meta', function () {
    $meta = queryFor()->meta();

    expect($meta->loomVersion)->toBe('0.3.0')
        ->and($meta->laravelVersion)->toBe('12.x');
});

// sections / list -------------------------------------------------------------

it('describes every section in registry order', function () {
    $sections = queryFor()->sections();
    $byName = [];
    foreach ($sections as $info) {
        $byName[$info->section->value] = $info;
    }

    expect($sections)->toHaveCount(count(Sections::cases()))
        ->and($byName['events']->count)->toBe(5)
        ->and($byName['events']->detailKind)->toBe(EntityKind::EVENT)
        ->and($byName['routes']->detailKind)->toBeNull()
        ->and($byName['model_events']->listed)->toBeFalse();
});

it('lists a section with pagination', function () {
    $page = queryFor()->list(Sections::EVENTS, new SectionQuery(perPage: 2, page: 2));

    expect($page->total)->toBe(5)
        ->and($page->items)->toHaveCount(2)
        ->and($page->page)->toBe(2)
        ->and($page->lastPage())->toBe(3)
        ->and(queryFor()->list(Sections::EVENTS)->items)->toHaveCount(5);
});

it('clamps out-of-range paging', function () {
    $page = queryFor()->list(Sections::EVENTS, new SectionQuery(page: -3, perPage: 0));

    expect($page->page)->toBe(1)
        ->and($page->perPage)->toBe(1)
        ->and($page->items)->toHaveCount(1);
});

it('sorts by name in both directions', function () {
    $q = queryFor();
    $names = static fn ($page) => array_map(static fn (Event $x) => substr($x->fqcn, 11), $page->items);

    expect($names($q->list(Sections::EVENTS, new SectionQuery(sort: SortField::NAME))))
        ->toBe(['Lonely', 'OrderPlaced', 'Ping', 'Pong', 'ReceiptSent'])
        ->and($names($q->list(Sections::EVENTS, new SectionQuery(sort: SortField::NAME, dir: SortDirection::DESC))))
        ->toBe(['ReceiptSent', 'Pong', 'Ping', 'OrderPlaced', 'Lonely']);
});

it('sorts by handler and dispatch counts, keeping source order on ties', function () {
    $q = queryFor();
    $names = static fn ($page) => array_map(static fn ($x) => $x->fqcn, $page->items);

    $byDispatch = $q->list(Sections::LISTENERS, new SectionQuery(sort: SortField::DISPATCH_COUNT, dir: SortDirection::DESC));
    expect($names($byDispatch)[0])->toBe('App\\Listeners\\SendReceipt');

    $byHandlers = $q->list(Sections::LISTENERS, new SectionQuery(sort: SortField::HANDLER_COUNT));
    expect($names($byHandlers)[0])->toBe('App\\Listeners\\Idle');
});

it('filters by property value and by search text', function () {
    $q = queryFor();

    $queued = $q->list(Sections::LISTENERS, new SectionQuery(filters: ['queued' => true]));
    expect($queued->total)->toBe(1)
        ->and($queued->items[0]->fqcn)->toBe('App\\Listeners\\PingListener');

    $enum = $q->list(Sections::LISTENERS, new SectionQuery(filters: ['registration' => 'auto_discovered']));
    expect($enum->total)->toBe(5);

    expect($q->list(Sections::LISTENERS, new SectionQuery(filters: ['nope' => 'x']))->total)->toBe(0);

    $search = $q->list(Sections::EVENTS, new SectionQuery(search: 'pong'));
    expect($search->total)->toBe(1);

    $byFile = $q->list(Sections::LISTENERS, new SectionQuery(search: 'listeners/idle'));
    expect($byFile->total)->toBe(1);
});

it('lists every section without error', function () {
    $q = queryFor();

    foreach (Sections::cases() as $section) {
        expect($q->list($section)->total)->toBeInt();
    }
});

it('sorts routes by displayed uri and by verb-prefixed name', function () {
    $q = queryFor();
    $label = static fn (Route $r) => $r->method.' '.$r->uri;

    expect(array_map($label, $q->list(Sections::ROUTES, new SectionQuery(sort: SortField::URI))->items))
        ->toBe(['GET health', 'POST orders', 'GET /ping'])
        ->and(array_map($label, $q->list(Sections::ROUTES, new SectionQuery(sort: SortField::NAME))->items))
        ->toBe(['GET /ping', 'GET health', 'POST orders']);
});

// dashboard -------------------------------------------------------------------

it('builds dashboard counts, health numbers and ranked fan-out', function () use ($e) {
    $dash = queryFor()->dashboard();

    expect($dash->stats['events'])->toBe(5)
        ->and($dash->stats)->toHaveKey('model_events')
        ->and($dash->orphanEventCount)->toBe(1)
        ->and($dash->idleListenerCount)->toBe(1)
        ->and($dash->unresolvedDispatchCount)->toBe(1)
        ->and($dash->meta->scannedAt)->toBe('2026-01-01T00:00:00Z');

    // ReceiptSent has two handlers (listener + closure); the rest have one.
    expect($dash->biggestFanOut[0]->event)->toBe($e('ReceiptSent'))
        ->and($dash->biggestFanOut[0]->handlerCount)->toBe(2)
        ->and($dash->biggestFanOut[0]->downstreamReach)->toBe(2);
});

it('orders equal fan-out by downstream reach then name, and honours the limit', function () use ($e) {
    $q = queryFor();
    $all = $q->dashboard(10)->biggestFanOut;

    expect(array_map(static fn ($f) => $f->event, $all))
        ->toBe([$e('ReceiptSent'), $e('OrderPlaced'), $e('Ping'), $e('Pong')])
        ->and($q->dashboard(1)->biggestFanOut)->toHaveCount(1)
        ->and($q->dashboard(0)->biggestFanOut)->toBe([]);
});

// search ----------------------------------------------------------------------

it('scores search hits: exact, short name, prefix, substring, file', function () {
    $q = queryFor();

    $exact = $q->search('App\\Events\\Ping');
    expect($exact[0]->score)->toBe(100)->and($exact[0]->detailRef)->toBe('App\\Events\\Ping');

    expect($q->search('ping')[0]->score)->toBe(90);

    $prefix = array_values(array_filter($q->search('order'), static fn ($h) => $h->label === 'App\\Events\\OrderPlaced'));
    expect($prefix[0]->score)->toBe(70);

    $contains = array_values(array_filter($q->search('laced'), static fn ($h) => $h->label === 'App\\Events\\OrderPlaced'));
    expect($contains[0]->score)->toBe(50);

    $file = $q->search('app/Mail/');
    expect($file[0]->label)->toBe('App\\Mail\\Receipt')->and($file[0]->score)->toBe(20);
});

it('searches routes and closure listeners', function () {
    $q = queryFor();

    $route = $q->search('orders.store');
    expect($route[0]->section)->toBe(Sections::ROUTES)
        ->and($route[0]->detailRef)->toBe('POST orders')
        ->and($route[0]->score)->toBe(90);

    $closure = array_values(array_filter($q->search('EventServiceProvider'), static fn ($h) => $h->section === Sections::CLOSURE_LISTENERS));
    expect($closure[0]->detailRef)->toBe('app/Providers/EventServiceProvider.php:30');
});

it('breaks search ties by label, applies the limit, and ignores empty terms', function () {
    $q = queryFor();

    $hits = $q->search('receipt');
    $labels = array_map(static fn ($h) => $h->label, $hits);
    $sameScore = array_values(array_filter($hits, static fn ($h) => $h->score === $hits[0]->score));
    $sortedLabels = array_map(static fn ($h) => $h->label, $sameScore);
    $expected = $sortedLabels;
    sort($expected);

    expect($sortedLabels)->toBe($expected)
        ->and($q->search('receipt', 2))->toHaveCount(2)
        ->and($q->search('   '))->toBe([])
        ->and($q->search('zzzz-none'))->toBe([])
        ->and(count($labels))->toBeGreaterThan(2);
});

it('clamps an out-of-range page to the last page', function () {
    $page = queryFor()->list(Sections::EVENTS, new SectionQuery(page: 999, perPage: 2));

    expect($page->page)->toBe($page->lastPage())->and($page->items)->not->toBeEmpty();
});

it('matches a route by leading-slash search', function () {
    expect(queryFor()->list(Sections::ROUTES, new SectionQuery(search: '/ping'))->total)->toBe(1)
        ->and(queryFor()->search('/ping'))->not->toBeEmpty();
});

// public result shapes --------------------------------------------------------

/** @return list<string> */
function propertyNames(string $class): array
{
    return array_map(
        static fn (ReflectionProperty $p): string => $p->getName(),
        (new ReflectionClass($class))->getProperties(),
    );
}

it('pins the property names of the read results', function () {
    expect(propertyNames(Dashboard::class))->toBe(['meta', 'stats', 'orphanEventCount', 'idleListenerCount', 'unresolvedDispatchCount', 'biggestFanOut'])
        ->and(propertyNames(FanOut::class))->toBe(['event', 'handlerCount', 'dispatchSiteCount', 'downstreamReach'])
        ->and(propertyNames(IndexMeta::class))->toBe(['loomVersion', 'scannedAt', 'laravelVersion'])
        ->and(propertyNames(Orphans::class))->toBe(['orphanEvents', 'idleListeners'])
        ->and(propertyNames(Page::class))->toBe(['items', 'total', 'page', 'perPage'])
        ->and(propertyNames(SearchHit::class))->toBe(['section', 'detailKind', 'label', 'subtitle', 'score', 'detailRef'])
        ->and(propertyNames(SectionInfo::class))->toBe(['section', 'count', 'present', 'listed', 'detailKind'])
        ->and(propertyNames(SectionQuery::class))->toBe(['search', 'filters', 'sort', 'dir', 'page', 'perPage'])
        ->and(propertyNames(ImpactReport::class))->toBe(['fqcn', 'change', 'kind', 'dispatchers', 'handlers', 'downstream', 'handles', 'wouldOrphanEvents', 'dispatches', 'notes']);
});

it('pins the wire keys of every query result', function () use ($e) {
    $q = queryFor();
    $edge = $q->eventChain($e('ReceiptSent'))->toArray()['edges'][0];
    $dispatch = $q->dispatchesFrom('App\\Listeners\\SendReceipt')[0]->toArray();

    expect(array_keys($edge))->toBe(['event', 'handler', 'handler_kind', 'dispatches'])
        ->and(array_keys($dispatch))->toBe(['target', 'kind', 'confidence', 'file', 'line'])
        ->and(array_keys($q->handlersFor($e('ReceiptSent'))->toArray()))->toBe(['event', 'count', 'listeners', 'closure_listeners'])
        ->and(array_keys($q->handlersFor($e('ReceiptSent'))->toArray()['listeners'][0]))->toBe(['listener', 'method', 'queued'])
        ->and(array_keys($q->handlersFor($e('ReceiptSent'))->toArray()['closure_listeners'][0]))->toBe(['file', 'line', 'queued'])
        ->and(array_keys($q->dispatchSitesFor($e('OrderPlaced'))->toArray()))->toBe(['event', 'count', 'dispatch_sites'])
        ->and(array_keys($q->dispatchSitesFor($e('OrderPlaced'))->toArray()['dispatch_sites'][0]))->toBe(['file', 'line', 'method'])
        ->and(array_keys($q->eventsFromMethod('App\\Listeners\\SendReceipt')->toArray()))->toBe(['method_fqcn', 'dispatches', 'chains'])
        ->and(array_keys($q->orphans()->toArray()))->toBe(['orphan_events', 'idle_listeners'])
        ->and(array_keys($q->orphans()->toArray()['orphan_events'][0]))->toBe(['fqcn', 'kind', 'file', 'line'])
        ->and(array_keys($q->orphans()->toArray()['idle_listeners'][0]))->toBe(['fqcn', 'file', 'line']);

    $cycle = $q->eventChain($e('Ping'), 6)->toArray()['cycles'][0];
    expect(array_keys($cycle))->toBe(['from_handler', 'back_to_event']);
});

it('pins the impact report keys for events, listeners and unknown classes', function () use ($e) {
    $q = queryFor();
    $event = $q->impactOfChange($e('ReceiptSent'))->toArray();

    expect(array_keys($event))->toBe(['fqcn', 'change', 'kind', 'dispatchers', 'handlers', 'downstream', 'notes'])
        ->and($event['kind'])->toBe('event')
        ->and(array_keys($event['handlers'][0]))->toBe(['handler', 'handler_kind'])
        ->and($event['handlers'][0]['handler'])->toBe('App\\Listeners\\ArchiveReceipt::handle')
        ->and($event['handlers'][1]['handler_kind'])->toBe('closure')
        ->and(array_keys($q->impactOfChange('App\\Listeners\\PingListener')->toArray()))
        ->toBe(['fqcn', 'change', 'kind', 'handles', 'would_orphan_events', 'dispatches', 'notes'])
        ->and(array_keys($q->impactOfChange('App\\Nope')->toArray()))
        ->toBe(['fqcn', 'change', 'kind', 'dispatchers', 'handlers', 'downstream', 'notes']);
});

it('always emits the route note key, null when a chain resolved', function () {
    $q = queryFor();
    $resolved = $q->routeChain('POST', 'orders')?->toArray();
    $closure = $q->routeChain('GET', 'health')?->toArray();

    expect(array_keys($resolved ?? []))->toBe(['route', 'note', 'chain'])
        ->and($resolved['note'])->toBeNull()
        ->and(array_keys($closure ?? []))->toBe(['route', 'note', 'chain'])
        ->and($closure['note'])->toBe(RouteChain::NO_CHAIN_NOTE)
        ->and($closure['chain'])->toBeNull()
        ->and(array_keys($resolved['route']))->toBe(['method', 'uri', 'name', 'controller_fqcn', 'controller_method', 'middleware', 'file', 'line']);
});

it('keys dashboard stats by every index section in body order', function () {
    $dash = queryFor()->dashboard();

    expect(array_keys($dash->stats))->toBe(SectionRegistry::names())
        ->and(array_keys($dash->stats))->toContain('model_events')
        ->and($dash->stats['model_events'])->toBe(1)
        ->and($dash->stats['scheduled_tasks'])->toBe(count(queryFor()->list(Sections::SCHEDULED_TASKS)->items));
});

it('lists every section in the registry order and flags only model_events as unlisted', function () {
    $sections = queryFor()->sections();

    expect(array_map(static fn ($s) => $s->section->value, $sections))->toBe(SectionRegistry::names());

    $unlisted = array_values(array_filter($sections, static fn ($s): bool => ! $s->listed));
    expect(array_map(static fn ($s) => $s->section, $unlisted))->toBe([Sections::MODEL_EVENTS]);
});

// deterministic ordering ------------------------------------------------------

it('breaks sort ties by name then file, whatever the input order', function () use ($e) {
    $names = ['Zed', 'Alpha', 'Mid'];
    $events = array_map(static fn (string $n): array => [
        'id' => "App\\Events\\{$n}", 'fqcn' => "App\\Events\\{$n}", 'kind' => 'class',
        'file' => "app/Events/{$n}.php", 'line' => 1, 'handled_by' => [], 'dispatched_from' => [],
    ], $names);

    $q = queryFor(['events' => $events]);
    $fqcns = static fn (Page $p): array => array_map(static fn (Event $x): string => $x->fqcn, $p->items);

    $asc = $q->list(Sections::EVENTS, new SectionQuery(sort: SortField::HANDLER_COUNT));
    $desc = $q->list(Sections::EVENTS, new SectionQuery(sort: SortField::DISPATCH_COUNT, dir: SortDirection::DESC));

    expect($fqcns($asc))->toBe([$e('Alpha'), $e('Mid'), $e('Zed')])
        ->and($fqcns($desc))->toBe([$e('Alpha'), $e('Mid'), $e('Zed')]);
});

it('returns an unsorted list in index order', function () use ($e) {
    $page = queryFor()->list(Sections::EVENTS);

    expect(array_map(static fn (Event $x): string => $x->fqcn, $page->items))
        ->toBe([$e('OrderPlaced'), $e('ReceiptSent'), $e('Ping'), $e('Pong'), $e('Lonely')]);
});

it('orders search hits by score, then label, then section', function () {
    $hits = queryFor()->search('Receipt');
    $keys = array_map(static fn (SearchHit $h): array => [-$h->score, $h->label, $h->section->value], $hits);
    $sorted = $keys;
    sort($sorted);

    expect($hits)->not->toBe([])
        ->and($keys)->toBe($sorted);
});

it('reports each cycle once even when the handler dispatches the target twice', function () {
    $data = require __DIR__.'/../../Fixtures/query-index.php';
    foreach ($data['listeners'] as &$listener) {
        if ($listener['fqcn'] === 'App\\Listeners\\PongListener') {
            $listener['dispatches'][] = [...$listener['dispatches'][0], 'line' => 9];
        }
    }
    unset($listener);

    $chain = queryFor(['listeners' => $data['listeners']])->eventChain('App\\Events\\Ping', 6);

    expect($chain->cycles)->toHaveCount(1);
});

it('ranks fan-out by handlers, then downstream reach, then event name', function () {
    $ranked = queryFor()->dashboard(10)->biggestFanOut;
    $keys = array_map(static fn (FanOut $f): array => [-$f->handlerCount, -$f->downstreamReach, $f->event], $ranked);
    $sorted = $keys;
    sort($sorted);

    expect($keys)->toBe($sorted);
});
