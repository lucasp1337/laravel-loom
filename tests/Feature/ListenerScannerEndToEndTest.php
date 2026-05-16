<?php

declare(strict_types=1);

use Lucasp\Loom\Index\IndexBuilder;
use Lucasp\Loom\Scanners\ListenerScanner;

function listenerEndToEndFixturePath(): string
{
    return dirname(__DIR__).'/Fixtures/listener-fixture-app';
}

it('produces a schema-valid index when only ListenerScanner is registered', function () {
    $builder = new IndexBuilder;
    $builder->register(new ListenerScanner);

    $payload = $builder->build(listenerEndToEndFixturePath(), '12.x')->toArray();

    expect($builder->validate($payload))->toBe([]);
});

it('counts discovered listeners in stats.listeners', function () {
    $builder = new IndexBuilder;
    $builder->register(new ListenerScanner);

    $payload = $builder->build(listenerEndToEndFixturePath(), '12.x')->toArray();

    // SendOrderConfirmation, UpdateInventory, NotifyAdmins, PsrOnly, UntypedListener,
    // and IssueInvoice (registered via Event::listen() in InvoicingServiceProvider,
    // which sits at app/Domain/Invoicing/ rather than app/Providers/).
    // NeverSeen is skipped (dynamic event); IgnoredListener is skipped (its $listen
    // array sits on a non-EventServiceProvider class, filtered by ListenArrayVisitor).
    expect($payload['stats']['listeners'])->toBe(6);
});

it('includes the known listener FQCNs', function () {
    $builder = new IndexBuilder;
    $builder->register(new ListenerScanner);

    $payload = $builder->build(listenerEndToEndFixturePath(), '12.x')->toArray();

    $fqcns = array_column($payload['listeners'], 'fqcn');

    expect($fqcns)->toContain('App\\Listeners\\SendOrderConfirmation');
    expect($fqcns)->toContain('App\\Listeners\\UpdateInventory');
    expect($fqcns)->toContain('App\\Listeners\\NotifyAdmins');
    expect($fqcns)->toContain('App\\Listeners\\PsrOnly');
    expect($fqcns)->toContain('App\\Listeners\\UntypedListener');
    expect($fqcns)->toContain('App\\Domain\\Invoicing\\Listeners\\IssueInvoice');
});

it('leaves non-listener sections as empty arrays, not null', function () {
    $builder = new IndexBuilder;
    $builder->register(new ListenerScanner);

    $payload = $builder->build(listenerEndToEndFixturePath(), '12.x')->toArray();

    expect($payload['events'])->toBe([]);
    expect($payload['observers'])->toBe([]);
    expect($payload['model_events'])->toBe([]);
    expect($payload['unresolved_dispatches'])->toBe([]);

    expect($payload['stats']['events'])->toBe(0);
    expect($payload['stats']['observers'])->toBe(0);
    expect($payload['stats']['unresolved_dispatches'])->toBe(0);
});

it('emits every listener with an empty dispatches array', function () {
    $builder = new IndexBuilder;
    $builder->register(new ListenerScanner);

    $payload = $builder->build(listenerEndToEndFixturePath(), '12.x')->toArray();

    foreach ($payload['listeners'] as $entry) {
        expect($entry['dispatches'])->toBe([]);
    }
});
