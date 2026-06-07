<?php

declare(strict_types=1);

use Lucasp\Loom\Index\CrossLink\CrossLinkContext;
use Lucasp\Loom\Index\CrossLink\RouteDispatchAttributionPhase;
use Lucasp\Loom\Index\Sections;

/**
 * Build a CrossLinkContext with hand-built routes entries and dispatch sites.
 * Mirrors {@see closureContext}: every section is zero-initialised, then the
 * routes section is overwritten. The phase keys routes by
 * "{controller_fqcn}::{controller_method}", so the FQCN indexes are irrelevant.
 *
 * @param  list<array<string, mixed>>  $routes
 * @param  list<array<string, mixed>>  $sites
 */
function routePhaseContext(array $routes, array $sites): CrossLinkContext
{
    $sections = [];
    foreach (Sections::cases() as $section) {
        $sections[$section->value] = [];
    }
    $sections[Sections::ROUTES->value] = $routes;

    return new CrossLinkContext(
        sections: $sections,
        dispatchSites: $sites,
        singleIndexes: [],
        observerIndex: [],
    );
}

/**
 * A routes[] entry as the RouteScanner emits it (dispatches starts empty — this
 * phase fills it).
 *
 * @return array<string, mixed>
 */
function routeEntry(?string $controllerFqcn, ?string $controllerMethod, string $uri = '/x'): array
{
    return [
        'method' => 'GET',
        'uri' => $uri,
        'name' => null,
        'controller_fqcn' => $controllerFqcn,
        'controller_method' => $controllerMethod,
        'middleware' => [],
        'file' => 'routes/web.php',
        'line' => 10,
        'dispatches' => [],
    ];
}

/**
 * A `_dispatch_sites` entry tagged with the controller routing keys the phase
 * matches on. Pass $inClosure=true to model a closure-internal site the phase
 * must ignore.
 *
 * @return array<string, mixed>
 */
function routeDispatchSite(
    string $classFqcn,
    string $method,
    string $target,
    string $kind,
    string $file = 'app/Http/Controllers/UserController.php',
    int $line = 12,
    string $confidence = 'high',
    bool $inClosure = false,
): array {
    return [
        'classFqcn' => $classFqcn,
        'method' => $method,
        'target' => $target,
        'form' => 'helper',
        'provisionalKind' => $kind,
        'file' => $file,
        'line' => $line,
        'confidence' => $confidence,
        'inClosure' => $inClosure,
    ];
}

it('attributes a controller-method dispatch site to its route', function () {
    $context = routePhaseContext(
        [routeEntry('App\\Http\\Controllers\\UserController', 'checkout')],
        [routeDispatchSite('App\\Http\\Controllers\\UserController', 'checkout', 'App\\Events\\OrderPlaced', 'event')],
    );

    (new RouteDispatchAttributionPhase)->apply($context);

    $dispatches = $context->sections[Sections::ROUTES->value][0]['dispatches'];
    expect($dispatches)->toBe([
        [
            'target' => 'App\\Events\\OrderPlaced',
            'kind' => 'event',
            'confidence' => 'high',
            'file' => 'app/Http/Controllers/UserController.php',
            'line' => 12,
        ],
    ]);
});

it('attributes both an event and a job dispatched from the same method', function () {
    $context = routePhaseContext(
        [routeEntry('App\\Http\\Controllers\\UserController', 'checkout')],
        [
            routeDispatchSite('App\\Http\\Controllers\\UserController', 'checkout', 'App\\Events\\OrderPlaced', 'event', line: 11),
            routeDispatchSite('App\\Http\\Controllers\\UserController', 'checkout', 'App\\Jobs\\ProcessOrder', 'job', line: 12),
        ],
    );

    (new RouteDispatchAttributionPhase)->apply($context);

    $dispatches = $context->sections[Sections::ROUTES->value][0]['dispatches'];
    expect($dispatches)->toHaveCount(2);

    $kinds = array_column($dispatches, 'kind');
    expect($kinds)->toContain('event');
    expect($kinds)->toContain('job');
});

it('does not attribute a site whose class does not match any route', function () {
    $context = routePhaseContext(
        [routeEntry('App\\Http\\Controllers\\UserController', 'checkout')],
        [routeDispatchSite('App\\Http\\Controllers\\OtherController', 'checkout', 'App\\Events\\OrderPlaced', 'event')],
    );

    (new RouteDispatchAttributionPhase)->apply($context);

    expect($context->sections[Sections::ROUTES->value][0]['dispatches'])->toBe([]);
});

it('does not attribute a site whose method does not match the route', function () {
    $context = routePhaseContext(
        [routeEntry('App\\Http\\Controllers\\UserController', 'checkout')],
        [routeDispatchSite('App\\Http\\Controllers\\UserController', 'store', 'App\\Events\\OrderPlaced', 'event')],
    );

    (new RouteDispatchAttributionPhase)->apply($context);

    expect($context->sections[Sections::ROUTES->value][0]['dispatches'])->toBe([]);
});

it('skips a route with a null controller (closure route)', function () {
    $context = routePhaseContext(
        [routeEntry(null, null, '/closure')],
        [routeDispatchSite('App\\Http\\Controllers\\UserController', 'checkout', 'App\\Events\\OrderPlaced', 'event')],
    );

    (new RouteDispatchAttributionPhase)->apply($context);

    expect($context->sections[Sections::ROUTES->value][0]['dispatches'])->toBe([]);
});

it('ignores a closure-internal site even when its routing keys match a route', function () {
    $context = routePhaseContext(
        [routeEntry('App\\Http\\Controllers\\UserController', 'checkout')],
        [routeDispatchSite('App\\Http\\Controllers\\UserController', 'checkout', 'App\\Events\\OrderPlaced', 'event', inClosure: true)],
    );

    (new RouteDispatchAttributionPhase)->apply($context);

    expect($context->sections[Sections::ROUTES->value][0]['dispatches'])->toBe([]);
});

it('skips an ambiguous-kind site so the route keeps no dispatch', function () {
    $context = routePhaseContext(
        [routeEntry('App\\Http\\Controllers\\UserController', 'checkout')],
        [routeDispatchSite('App\\Things\\Thing', 'checkout', 'App\\Things\\Thing', 'ambiguous')],
    );

    (new RouteDispatchAttributionPhase)->apply($context);

    expect($context->sections[Sections::ROUTES->value][0]['dispatches'])->toBe([]);
});

it('attributes a shared controller-method site to every route bound to it', function () {
    // Resource expansion can bind two route entries to the same controller
    // method; a single dispatch site must reach both.
    $context = routePhaseContext(
        [
            routeEntry('App\\Http\\Controllers\\UserController', 'checkout', '/checkout'),
            routeEntry('App\\Http\\Controllers\\UserController', 'checkout', '/admin/checkout'),
        ],
        [routeDispatchSite('App\\Http\\Controllers\\UserController', 'checkout', 'App\\Events\\OrderPlaced', 'event')],
    );

    (new RouteDispatchAttributionPhase)->apply($context);

    expect($context->sections[Sections::ROUTES->value][0]['dispatches'])->toHaveCount(1);
    expect($context->sections[Sections::ROUTES->value][1]['dispatches'])->toHaveCount(1);
});
