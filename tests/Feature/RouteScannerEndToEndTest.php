<?php

declare(strict_types=1);

use Lucasp\Loom\Index\IndexBuilder;
use Lucasp\Loom\Scanners\DispatchScanner;
use Lucasp\Loom\Scanners\EventScanner;
use Lucasp\Loom\Scanners\JobsScanner;
use Lucasp\Loom\Scanners\ListenerScanner;
use Lucasp\Loom\Scanners\RouteScanner;
use Lucasp\Loom\Scanners\ScheduleScanner;

function routeEndToEndFixturePath(): string
{
    return dirname(__DIR__).'/Fixtures/route-fixture-app';
}

/**
 * @return array<string, mixed>
 */
function buildRouteEndToEndPayload(): array
{
    $builder = new IndexBuilder;
    $builder->register(new EventScanner);
    $builder->register(new ListenerScanner);
    $builder->register(new JobsScanner);
    $builder->register(new DispatchScanner);
    $builder->register(new ScheduleScanner);
    $builder->register(new RouteScanner);

    return $builder->build(routeEndToEndFixturePath(), '12.x')->toArray();
}

it('produces a schema-valid index with RouteScanner registered', function () {
    $builder = new IndexBuilder;
    $builder->register(new EventScanner);
    $builder->register(new ListenerScanner);
    $builder->register(new JobsScanner);
    $builder->register(new DispatchScanner);
    $builder->register(new ScheduleScanner);
    $builder->register(new RouteScanner);

    $payload = $builder->build(routeEndToEndFixturePath(), '12.x')->toArray();

    expect($builder->validate($payload))->toBe([]);
});

it('reports stats.routes equal to the count of route entries', function () {
    $payload = buildRouteEndToEndPayload();

    expect($payload)->toHaveKey('routes');
    expect($payload['stats'])->toHaveKey('routes');
    expect($payload['stats']['routes'])->toBe(count($payload['routes']));
    expect($payload['stats']['routes'])->toBeGreaterThan(0);
});

it('serialises a known route with the expected schema keys and values', function () {
    $payload = buildRouteEndToEndPayload();

    /** @var array<int, array<string, mixed>> $routes */
    $routes = $payload['routes'];

    $home = null;
    foreach ($routes as $route) {
        if ($route['method'] === 'GET' && $route['uri'] === '/') {
            $home = $route;

            break;
        }
    }

    expect($home)->not->toBeNull();

    foreach (['method', 'uri', 'name', 'controller_fqcn', 'controller_method', 'file', 'line'] as $key) {
        expect($home)->toHaveKey($key);
    }

    expect($home['method'])->toBe('GET');
    expect($home['uri'])->toBe('/');
    expect($home['name'])->toBe('home');
    expect($home['controller_fqcn'])->toBe('App\\Http\\Controllers\\HomeController');
    expect($home['controller_method'])->toBe('index');
    expect(str_replace(DIRECTORY_SEPARATOR, '/', (string) $home['file']))->toBe('routes/web.php');
    expect($home['line'])->toBe(17);
});

it('serialises a grouped route with the resolved prefix, name, and controller', function () {
    $payload = buildRouteEndToEndPayload();

    /** @var array<int, array<string, mixed>> $routes */
    $routes = $payload['routes'];

    $dash = null;
    foreach ($routes as $route) {
        if ($route['method'] === 'GET' && $route['uri'] === '/dash/home') {
            $dash = $route;

            break;
        }
    }

    expect($dash)->not->toBeNull();
    expect($dash['uri'])->toBe('/dash/home');
    expect($dash['name'])->toBe('dash.home');
    expect($dash['controller_fqcn'])->toBe('App\\Http\\Controllers\\HomeController');
    expect($dash['controller_method'])->toBe('index');
});

it('serialises the middleware field on a grouped route', function () {
    $payload = buildRouteEndToEndPayload();

    /** @var array<int, array<string, mixed>> $routes */
    $routes = $payload['routes'];

    $settings = null;
    foreach ($routes as $route) {
        if ($route['method'] === 'GET' && $route['uri'] === '/account/settings') {
            $settings = $route;

            break;
        }
    }

    expect($settings)->not->toBeNull();
    expect($settings)->toHaveKey('middleware');
    expect($settings['middleware'])->toBe(['web', 'auth', 'verified']);
});

it('serialises an expanded resource route end-to-end', function () {
    $payload = buildRouteEndToEndPayload();

    /** @var array<int, array<string, mixed>> $routes */
    $routes = $payload['routes'];

    $update = null;
    foreach ($routes as $route) {
        if ($route['method'] === 'PUT' && $route['uri'] === '/photos/{photo}') {
            $update = $route;

            break;
        }
    }

    expect($update)->not->toBeNull();
    expect($update['name'])->toBe('photos.update');
    expect($update['controller_fqcn'])->toBe('App\\Http\\Controllers\\PhotoController');
    expect($update['controller_method'])->toBe('update');
});

it('leaves the listener-shaped sections empty for the route fixture', function () {
    $payload = buildRouteEndToEndPayload();

    // The fixture carries an event (App\Events\OrderPlaced) and a job
    // (App\Jobs\ProcessOrder) so the cross-link can attribute dispatches to the
    // /checkout route; every other section stays empty.
    expect($payload['listeners'])->toBe([]);
    expect($payload['observers'])->toBe([]);
    expect($payload['model_events'])->toBe([]);
    expect($payload['unresolved_dispatches'])->toBe([]);
    expect($payload['closure_listeners'])->toBe([]);
    expect($payload['scheduled'])->toBe([]);
    expect($payload['mailables'])->toBe([]);
    expect($payload['notifications'])->toBe([]);
});

// ---------------------------------------------------------------------------
// Dispatch attribution (slice 5): the cross-link populates routes[*].dispatches
// ---------------------------------------------------------------------------

/**
 * Locate a serialised route by (method, uri).
 *
 * @param  array<int, array<string, mixed>>  $routes
 * @return array<string, mixed>|null
 */
function serialisedRouteBy(array $routes, string $method, string $uri): ?array
{
    foreach ($routes as $route) {
        if ($route['method'] === $method && $route['uri'] === $uri) {
            return $route;
        }
    }

    return null;
}

it('attributes controller-method dispatches to the route via cross-link', function () {
    $payload = buildRouteEndToEndPayload();

    /** @var array<int, array<string, mixed>> $routes */
    $routes = $payload['routes'];

    $checkout = serialisedRouteBy($routes, 'GET', '/checkout');

    expect($checkout)->not->toBeNull();
    expect($checkout)->toHaveKey('dispatches');

    /** @var array<int, array<string, mixed>> $dispatches */
    $dispatches = $checkout['dispatches'];
    expect($dispatches)->toHaveCount(2);

    $byTarget = [];
    foreach ($dispatches as $dispatch) {
        foreach (['target', 'kind', 'confidence', 'file', 'line'] as $key) {
            expect($dispatch)->toHaveKey($key);
        }
        $byTarget[$dispatch['target']] = $dispatch;
    }

    expect($byTarget)->toHaveKey('App\\Events\\OrderPlaced');
    expect($byTarget['App\\Events\\OrderPlaced']['kind'])->toBe('event');

    expect($byTarget)->toHaveKey('App\\Jobs\\ProcessOrder');
    expect($byTarget['App\\Jobs\\ProcessOrder']['kind'])->toBe('job');
});

it('leaves dispatches empty for controller and closure routes that dispatch nothing', function () {
    $payload = buildRouteEndToEndPayload();

    /** @var array<int, array<string, mixed>> $routes */
    $routes = $payload['routes'];

    $store = serialisedRouteBy($routes, 'POST', '/users');
    expect($store)->not->toBeNull();
    expect($store['controller_method'])->toBe('store');
    expect($store['dispatches'])->toBe([]);

    $closure = serialisedRouteBy($routes, 'GET', '/closure');
    expect($closure)->not->toBeNull();
    expect($closure['controller_fqcn'])->toBeNull();
    expect($closure['dispatches'])->toBe([]);
});
