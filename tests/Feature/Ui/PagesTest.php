<?php

declare(strict_types=1);

use Livewire\Livewire;
use Lucasp\Loom\Index\Sections;
use Lucasp\Loom\Query\ChainDepth;
use Lucasp\Loom\Tests\Feature\Ui\UiSnapshot;
use Lucasp\Loom\Ui\Livewire\ChainPage;
use Lucasp\Loom\Ui\Livewire\Dashboard;
use Lucasp\Loom\Ui\Livewire\EntityDetail;
use Lucasp\Loom\Ui\Livewire\EventDetail;
use Lucasp\Loom\Ui\Livewire\Palette;
use Lucasp\Loom\Ui\Livewire\SectionIndex;
use Lucasp\Loom\Ui\Support\AppChangeClock;
use Lucasp\Loom\Ui\UiContext;

uses(UiSnapshot::class);

const ORDER = 'App.Events.OrderPlaced';

it('renders the dashboard with counts, orphans and fan-out', function () {
    Livewire::test(Dashboard::class)
        ->assertSee('Biggest fan-out')
        ->assertSee('App\Events\Lonely')
        ->assertSee('App\Listeners\Idle')
        ->assertSee('Events');
});

it('lists every section with data through the generic index', function (Sections $section) {
    $this->get('/loom/'.$section->value)->assertOk();
    Livewire::test(SectionIndex::class, ['section' => $section->value])->assertOk();
})->with(fn () => Sections::cases());

it('renders the sidebar with counts and hides model_events', function () {
    $this->get('/loom')
        ->assertSee('Listeners')
        ->assertSee('href="'.route('loom.section', 'closure_listeners').'"', false)
        ->assertDontSee('Model events');
});

it('filters rows by search', function () {
    Livewire::test(SectionIndex::class, ['section' => 'events'])
        ->assertSee('Lonely')->assertSee('OrderPlaced')
        ->set('search', 'lonely')
        ->assertSee('Lonely')->assertDontSee('OrderPlaced')
        ->set('search', 'zzz-none')
        ->assertSee('No matches for "zzz-none"', false)
        ->call('clearFilter')
        ->assertSee('OrderPlaced');
});

it('sorts rows and toggles direction', function () {
    $order = static function ($test): array {
        $html = $test->html();
        preg_match_all('/App\\\\Events\\\\(\w+)</', $html, $m);

        return array_values(array_unique($m[1]));
    };

    $test = Livewire::test(SectionIndex::class, ['section' => 'events']);
    expect($order($test)[0])->toBe('Lonely');

    // Name ascending is the default, so the first click flips it.
    $test->call('sortBy', 'name')->assertSet('dir', 'desc');
    expect($order($test)[0])->toBe('ReceiptSent');

    $test->call('sortBy', 'handlers')->assertSet('sort', 'handlers')->assertSet('dir', 'asc');
});

it('ignores an unknown sort field', function () {
    Livewire::test(SectionIndex::class, ['section' => 'events'])
        ->call('sortBy', 'bogus')
        ->assertSet('sort', '');
});

it('syncs filter and sort to the url', function () {
    $this->get('/loom/events?q=ping&sort=name&dir=desc')
        ->assertOk()
        ->assertSee('Ping')
        ->assertDontSee('OrderPlaced');
});

it('shows a per-section empty state', function () {
    Livewire::test(SectionIndex::class, ['section' => 'scheduled'])
        ->assertSee('No scheduled tasks in this index');
});

it('flags orphan events', function () {
    Livewire::test(SectionIndex::class, ['section' => 'events'])->assertSee('orphan');
});

it('renders the event detail page', function () {
    $this->get('/loom/events/'.ORDER)
        ->assertOk()
        ->assertSee('View chain')
        ->assertSee('OrderController')
        ->assertSee('SendReceipt')
        ->assertSee('ReceiptSent');

    Livewire::test(EventDetail::class, ['fqcn' => ORDER])->assertOk();
});

it('renders the generic detail page for other entity kinds', function () {
    $this->get('/loom/listeners/App.Listeners.SendReceipt')->assertOk()->assertSee('SendReceipt')->assertSee('Dispatches');
    $this->get('/loom/jobs/App.Jobs.SendMail')->assertOk();
    $this->get('/loom/mailables/App.Mail.Receipt')->assertOk();
    $this->get('/loom/notifications/App.Notifications.Shipped')->assertOk()->assertSee('mail');
    $this->get('/loom/observers/App.Observers.OrderObserver')->assertOk()->assertSee('created');

    Livewire::test(EntityDetail::class, ['section' => 'jobs', 'fqcn' => 'App.Jobs.SendMail'])->assertOk();
});

it('returns 404 for a class not in the index', function () {
    $this->get('/loom/events/App.Events.Nope')->assertNotFound()->assertSee('Class not in index');
    $this->get('/loom/chain/App.Events.Nope')->assertNotFound()->assertSee('Class not in index');
    $this->get('/loom/jobs/App.Jobs.Nope')->assertNotFound();
});

it('rejects an unknown section', function () {
    $this->get('/loom/widgets')->assertNotFound();
});

it('renders the chain page with graph data and cytoscape only there', function () {
    $this->get('/loom/chain/'.ORDER)
        ->assertOk()
        ->assertSee('cytoscape.min.js', false)
        ->assertSee('loomChain', false);

    $this->get('/loom/events')->assertDontSee('cytoscape.min.js', false);
    $this->get('/loom')->assertDontSee('cytoscape.min.js', false);
});

it('walks the chain to the configured depth', function () {
    $depth1 = Livewire::test(ChainPage::class, ['fqcn' => ORDER])->set('depth', 1)->viewData('graph');
    $depth3 = Livewire::test(ChainPage::class, ['fqcn' => ORDER])->viewData('graph');

    // Depth 1 expands OrderPlaced only; what SendReceipt dispatches is drawn but not expanded.
    expect(collect($depth1['nodes'])->pluck('id')->all())->toBe(['App\Events\OrderPlaced', 'App\Listeners\SendReceipt', 'App\Events\ReceiptSent', 'App\Jobs\SendMail'])
        ->and(collect($depth3['nodes'])->pluck('id'))->toContain('App\Listeners\ArchiveReceipt')
        ->and(count($depth3['nodes']))->toBeGreaterThan(count($depth1['nodes']))
        ->and($depth3['depth'])->toBe(3);
});

it('clamps chain depth to the shared bounds', function () {
    $test = Livewire::test(ChainPage::class, ['fqcn' => ORDER])->call('setDepth', 99);
    expect($test->get('depth'))->toBe(ChainDepth::MAX);

    $test->call('setDepth', 0);
    expect($test->get('depth'))->toBe(1);
});

it('marks an already-shown event as a cycle instead of looping', function () {
    $graph = Livewire::test(ChainPage::class, ['fqcn' => 'App.Events.Ping'])->set('depth', 5)->viewData('graph');
    $types = collect($graph['nodes'])->pluck('type');

    expect($types)->toContain('cycle')
        ->and(collect($graph['nodes'])->firstWhere('type', 'cycle')['label'])->toContain('already shown')
        ->and(count($graph['nodes']))->toBeLessThan(20);
});

it('collapses and expands a node by its path key', function () {
    $test = Livewire::test(ChainPage::class, ['fqcn' => ORDER]);
    $before = count($test->viewData('graph')['nodes']);
    $key = collect($test->viewData('graph')['nodes'])->firstWhere('type', 'listener')['key'];

    $test->call('toggle', $key);
    expect(count($test->viewData('graph')['nodes']))->toBeLessThan($before)
        ->and(collect($test->viewData('graph')['nodes'])->firstWhere('key', $key)['collapsed'])->toBeTrue();

    $test->call('toggle', $key);
    expect(count($test->viewData('graph')['nodes']))->toBe($before);
});

it('selects the root by default and shows node facts in the panel', function () {
    Livewire::test(ChainPage::class, ['fqcn' => ORDER])
        ->assertSet('node', 'App\Events\OrderPlaced')
        ->assertSee('Handlers')
        ->call('select', 'App\Events\OrderPlaced>App\Listeners\SendReceipt::handle')
        ->assertSee('Registration')
        ->call('select', null)
        ->assertSee('Select a node to see its details.');
});

it('drops a selected node that is no longer in the graph', function () {
    Livewire::test(ChainPage::class, ['fqcn' => ORDER])
        ->call('select', 'App\Events\OrderPlaced>Gone')
        ->assertSet('node', null);
});

it('captions an event without handlers', function () {
    Livewire::test(ChainPage::class, ['fqcn' => 'App.Events.Lonely'])
        ->assertSee('No handlers registered for this event.');
});

it('searches across entities from the palette', function () {
    Livewire::test(Palette::class)
        ->assertSee('Type to search')
        ->set('term', 'receipt')
        ->assertSee('SendReceipt')
        ->assertSee('Receipt')
        ->set('term', 'zzzz')
        ->assertSee('No class or method matches "zzzz".', false);
});

it('applies the stale banner when app/ changed after the scan', function () {
    app()->bind(
        AppChangeClock::class,
        fn () => new class implements AppChangeClock
        {
            public function lastChange(): ?int
            {
                return strtotime('2026-01-08T00:00:00+00:00');
            }
        },
    );

    $this->get('/loom')->assertSee('Index is 7 days older than your last commit to app/');
});

it('tolerates non-numeric page and depth query values', function () {
    $this->get('/loom/events?page=abc')->assertOk();
    $this->get('/loom/events?page=999')->assertOk()->assertSee('rows');
    $this->get('/loom/chain/App.Events.OrderPlaced?depth=abc')->assertOk();
});

it('keeps a search term of zero in section links', function () {
    expect(app(UiContext::class)->links->section(Sections::EVENTS, '0'))
        ->toContain('q=0');
});
