<?php

declare(strict_types=1);

use Lucasp\Loom\Index\IndexBuilder;
use Lucasp\Loom\Scanners\ObserverScanner;

function observerEndToEndFixturePath(): string
{
    return dirname(__DIR__).'/Fixtures/observer-fixture-app';
}

it('produces a schema-valid index when only ObserverScanner is registered', function () {
    $builder = new IndexBuilder;
    $builder->register(new ObserverScanner);

    $payload = $builder->build(observerEndToEndFixturePath(), '12.x')->toArray();

    expect($builder->validate($payload))->toBe([]);
});

it('counts the observer entries in stats.observers', function () {
    $builder = new IndexBuilder;
    $builder->register(new ObserverScanner);

    $payload = $builder->build(observerEndToEndFixturePath(), '12.x')->toArray();

    expect($payload['stats']['observers'])->toBe(4);
});

it('populates the model_events section with the expected number of entries', function () {
    $builder = new IndexBuilder;
    $builder->register(new ObserverScanner);

    $payload = $builder->build(observerEndToEndFixturePath(), '12.x')->toArray();

    expect($payload['model_events'])->toHaveCount(10);
});

it('includes the known observer FQCNs', function () {
    $builder = new IndexBuilder;
    $builder->register(new ObserverScanner);

    $payload = $builder->build(observerEndToEndFixturePath(), '12.x')->toArray();

    $fqcns = array_column($payload['observers'], 'fqcn');

    expect($fqcns)->toContain('App\\Observers\\UserObserver');
    expect($fqcns)->toContain('App\\Observers\\PostObserver');
    expect($fqcns)->toContain('App\\Observers\\CommentObserver');
    expect($fqcns)->toContain('App\\Observers\\DualObserver');
});

it('includes the known model_event ids', function () {
    $builder = new IndexBuilder;
    $builder->register(new ObserverScanner);

    $payload = $builder->build(observerEndToEndFixturePath(), '12.x')->toArray();

    $ids = array_column($payload['model_events'], 'id');

    expect($ids)->toContain('eloquent.creating: App\\Models\\User');
    expect($ids)->toContain('eloquent.updated: App\\Models\\User');
    expect($ids)->toContain('eloquent.deleted: App\\Models\\User');
    expect($ids)->toContain('eloquent.saved: App\\Models\\Post');
    expect($ids)->toContain('eloquent.creating: App\\Models\\Post');
    expect($ids)->toContain('eloquent.deleting: App\\Models\\Comment');
    expect($ids)->toContain('eloquent.restored: App\\Models\\Comment');
    expect($ids)->toContain('eloquent.created: App\\Models\\Widget');
    expect($ids)->toContain('eloquent.updated: App\\Models\\Widget');
    expect($ids)->toContain('eloquent.deleted: App\\Models\\Product');
});

it('leaves other sections as empty arrays', function () {
    $builder = new IndexBuilder;
    $builder->register(new ObserverScanner);

    $payload = $builder->build(observerEndToEndFixturePath(), '12.x')->toArray();

    expect($payload['events'])->toBe([]);
    expect($payload['listeners'])->toBe([]);
    expect($payload['unresolved_dispatches'])->toBe([]);

    expect($payload['stats']['events'])->toBe(0);
    expect($payload['stats']['listeners'])->toBe(0);
    expect($payload['stats']['unresolved_dispatches'])->toBe(0);
});
