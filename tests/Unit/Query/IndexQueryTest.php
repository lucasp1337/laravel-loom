<?php

declare(strict_types=1);

use Lucasp\Loom\Index\IndexLoader;
use Lucasp\Loom\Index\Model\Event;
use Lucasp\Loom\Index\Model\Route;
use Lucasp\Loom\Index\Sections;
use Lucasp\Loom\Query\ChainDepth;
use Lucasp\Loom\Query\ChainNodeKind;
use Lucasp\Loom\Query\ChangeKind;
use Lucasp\Loom\Query\Dto\SectionQuery;
use Lucasp\Loom\Query\EntityKind;
use Lucasp\Loom\Query\HandlerKind;
use Lucasp\Loom\Query\ImpactEntity;
use Lucasp\Loom\Query\ImpactNote;
use Lucasp\Loom\Query\IndexQuery;
use Lucasp\Loom\Query\SortDirection;
use Lucasp\Loom\Query\SortField;

/** @param  array<string, mixed>  $overrides */
function queryFor(array $overrides = []): IndexQuery
{
    /** @var array<string, mixed> $data */
    $data = array_merge(require __DIR__.'/../../Fixtures/query-index.php', $overrides);
    $index = (new IndexLoader)->fromArray($data);

    return new IndexQuery(new class($index) implements \Lucasp\Loom\Query\IndexSource
    {
        public function __construct(private readonly \Lucasp\Loom\Index\Index $index)
        {
        }

        public function index(): \Lucasp\Loom\Index\Index
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

it('keeps the legacy array shape free of cycles and truncated', function () use ($e) {
    $chain = queryFor()->eventChain($e('Ping'));

    expect(array_keys($chain->toArray()))->toBe(['root', 'depth', 'edges', 'events_reached'])
        ->and(array_keys($chain->toArray(true)))->toBe(['root', 'depth', 'edges', 'events_reached', 'cycles', 'truncated']);
});

it('projects a nested tree with back-references flagged as cycles', function () use ($e) {
    $tree = queryFor()->eventChain($e('Ping'), 6)->tree();

    expect($tree->kind)->toBe(ChainNodeKind::EVENT)
        ->and($tree->label)->toBe($e('Ping'))
        ->and($tree->isCycle)->toBeFalse();

    $handler = $tree->children[0];
    expect($handler->kind)->toBe(ChainNodeKind::HANDLER);

    $pong = $handler->children[0];
    expect($pong->label)->toBe($e('Pong'));

    $back = $pong->children[0]->children[0];
    expect($back->label)->toBe($e('Ping'))
        ->and($back->isCycle)->toBeTrue()
        ->and($back->children)->toBe([]);
});

it('marks non-event dispatch targets as leaves and emits cytoscape elements', function () use ($e) {
    $chain = queryFor()->eventChain($e('OrderPlaced'), 1);
    $handler = $chain->tree()->children[0];

    expect($handler->children[1]->kind)->toBe(ChainNodeKind::DISPATCH_TARGET)
        ->and($handler->children[1]->label)->toBe('App\\Jobs\\SendMail');

    $els = $chain->cytoscapeElements();
    $ids = array_map(static fn ($n) => $n['data']['id'], $els['nodes']);

    expect($ids)->toBe(array_values(array_unique($ids)))
        ->and(count($els['edges']))->toBe(count($els['nodes']) - 1)
        ->and($els['nodes'][0]['data']['kind'])->toBe('event');
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

    expect($set->total())->toBe(2)
        ->and($set->listeners[0]->listener)->toBe('App\\Listeners\\ArchiveReceipt')
        ->and($set->listeners[0]->queued)->toBeFalse()
        ->and($set->closureListeners[0]->queued)->toBeTrue()
        ->and(queryFor()->handlersFor($e('Ping'))->listeners[0]->queued)->toBeTrue()
        ->and(queryFor()->handlersFor('App\\Nope')->total())->toBe(0);
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
    expect($store?->chain?->method)->toBe('App\\Http\\Controllers\\OrderController::store');

    $invoke = $q->routeChain('GET', 'ping');
    // Pinned legacy quirk: the route stores a null method, but dispatches are
    // matched on the literal `__invoke`, so an invokable controller yields nothing.
    expect($invoke?->chain?->method)->toBe('App\\Http\\Controllers\\PingController::__invoke')
        ->and($invoke?->chain?->dispatches)->toBe([]);

    $closure = $q->routeChain('GET', 'health');
    expect($closure)->not->toBeNull()
        ->and($closure?->chain)->toBeNull()
        ->and($q->routeChain('PUT', 'x'))->toBeNull();
});

// impactOfChange --------------------------------------------------------------

it('reports impact for an event', function () use ($e) {
    $report = queryFor()->impactOfChange($e('OrderPlaced'), ChangeKind::RENAME);

    expect($report->entity)->toBe(ImpactEntity::EVENT)
        ->and($report->dispatchers)->toHaveCount(1)
        ->and($report->handlers[0]->kind)->toBe(HandlerKind::LISTENER)
        ->and($report->downstream?->depth)->toBe(ChainDepth::DEFAULT)
        ->and($report->notes)->toBe([ImpactNote::RENAME_TOUCHES_ALL, ImpactNote::DYNAMIC_DISPATCH_BLIND_SPOT]);
});

it('lists closure handlers in an event impact report', function () use ($e) {
    $report = queryFor()->impactOfChange($e('ReceiptSent'));

    expect($report->handlers[1]->method)->toBe('closure')
        ->and($report->handlers[1]->kind)->toBe(HandlerKind::CLOSURE)
        ->and($report->notes[0])->toBe(ImpactNote::REMOVE_ORPHANS_HANDLERS);
});

it('flags events a listener removal would orphan', function () use ($e) {
    $q = queryFor();

    $ping = $q->impactOfChange('App\\Listeners\\PingListener');
    expect($ping->entity)->toBe(ImpactEntity::LISTENER)
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
    expect($job->entity)->toBe(ImpactEntity::JOB)
        ->and($job->handles)->toBe([])
        ->and($job->dispatches)->toHaveCount(1);

    $unknown = $q->impactOfChange('App\\Nope');
    expect($unknown->entity)->toBe(ImpactEntity::UNKNOWN)
        ->and($unknown->notes)->toBe([ImpactNote::UNKNOWN_FQCN])
        ->and($unknown->toArray()['downstream'])->toBeNull();
});

it('emits note codes when no renderer is given', function () {
    expect(queryFor()->impactOfChange('App\\Nope')->toArray()['notes'])->toBe(['unknown_fqcn']);
});

// orphans / unresolved / entity / meta ----------------------------------------

it('finds orphan events and idle listeners', function () {
    $orphans = queryFor()->orphans();

    expect(array_map(static fn (Event $x) => $x->fqcn, $orphans->events))->toBe(['App\\Events\\Lonely'])
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
        ->and($byName['model_events']->inStats)->toBeFalse();
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

it('names routes by verb and uri when sorting', function () {
    $page = queryFor()->list(Sections::ROUTES, new SectionQuery(sort: SortField::NAME));

    expect(array_map(static fn (Route $r) => $r->method.' '.$r->uri, $page->items))
        ->toBe(['GET /ping', 'GET health', 'POST orders']);
});

// dashboard -------------------------------------------------------------------

it('builds dashboard counts, health numbers and ranked fan-out', function () use ($e) {
    $dash = queryFor()->dashboard();

    expect($dash->counts['events'])->toBe(5)
        ->and($dash->counts)->not->toHaveKey('model_events')
        ->and($dash->orphanEventCount)->toBe(1)
        ->and($dash->idleListenerCount)->toBe(1)
        ->and($dash->unresolvedCount)->toBe(1)
        ->and($dash->meta->scannedAt)->toBe('2026-01-01T00:00:00+00:00');

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
