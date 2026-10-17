<?php

declare(strict_types=1);

use Lucasp\Loom\Index\IndexBuilder;
use Lucasp\Loom\Scanners\DefaultScanners;
use Lucasp\Loom\Support\ScanScope;

function formsRoot(): string
{
    return dirname(__DIR__).'/Fixtures/route-forms-fixture-app';
}

/**
 * Scan the fixture and return its payload.
 *
 * @param  array<string, string>  $macros
 * @return array<string, mixed>
 */
function formsPayload(array $macros = []): array
{
    $builder = new IndexBuilder;
    DefaultScanners::registerOn($builder, new ScanScope(routeMacros: $macros));

    return $builder->build(formsRoot(), '12.x')->toArray();
}

/**
 * Routes keyed by "METHOD uri".
 *
 * @return array<string, array<string, mixed>>
 */
function formsRoutes(array $macros = []): array
{
    $out = [];
    foreach (formsPayload($macros)['routes'] as $route) {
        $out[$route['method'].' '.$route['uri']] = $route;
    }

    return $out;
}

/**
 * Unresolved route reasons keyed by "file:line".
 *
 * @return array<string, list<string>>
 */
function formsUnresolved(array $macros = []): array
{
    $out = [];
    foreach (formsPayload($macros)['unresolved_routes'] as $entry) {
        $out[$entry['file'].':'.$entry['line']][] = $entry['reason'];
    }

    return $out;
}

it('keeps the group of a route file required inside a group closure', function () {
    $routes = formsRoutes();

    expect($routes)->toHaveKey('GET /api/users')
        ->and($routes['GET /api/users']['middleware'])->toBe(['api', 'auth'])
        ->and($routes['GET /nested']['middleware'])->toBe(['web']);
});

it('reports a require inside a group whose path is not static', function () {
    expect(formsUnresolved()['app/Providers/RouteServiceProvider.php:19'])->toBe(['route_file_dynamic']);
});

it('reads chains that start with registrar attributes', function () {
    $routes = formsRoutes();

    expect($routes['GET /home']['middleware'])->toBe(['auth'])
        ->and($routes['POST /v1/sync']['name'])->toBe('v1.')
        ->and($routes['GET /x/last']['middleware'])->toBe(['auth:admin'])
        ->and($routes['GET /pp/after']['name'])->toBe('ab');
});

it('applies a leading middleware and name to a resource', function () {
    $routes = formsRoutes();

    expect($routes['GET /photos']['middleware'])->toBe(['auth'])
        ->and($routes['GET /named']['name'])->toBe('x..named.index');
});

it('applies the resource options array', function () {
    $routes = formsRoutes();

    expect($routes)->toHaveKeys(['GET /items', 'GET /items/{item_id}'])
        ->and($routes)->not->toHaveKey('POST /items')
        ->and($routes['GET /items']['name'])->toBe('api.items.index')
        ->and($routes['GET /items/{item_id}']['middleware'])->toBe(['auth']);
});

it('applies resource modifier chains in order', function () {
    $routes = formsRoutes();

    expect($routes['GET /lists']['name'])->toBe('lists.all')
        ->and($routes['GET /lists/{list}']['middleware'])->toBe(['a', 'b'])
        ->and($routes['PUT /lists/{list}']['middleware'])->toBe(['a'])
        ->and($routes['PATCH /lists/{list}']['name'])->toBe('lists.update');
});

it('mirrors the resource registrar for wildcards, nesting and verbs', function () {
    $routes = formsRoutes();

    expect($routes)->toHaveKeys([
        'GET /fiscal-positions/{fiscal_position}',
        'PUT /fiscal-positions/{fiscal_position}',
        'PATCH /fiscal-positions/{fiscal_position}',
        'GET /terms/{term}/due-terms',
        'GET /due-terms/{due_term}',
        'GET /admin/photos2/{photos2}',
    ])
        ->and($routes['GET /due-terms/{due_term}']['name'])->toBe('due-terms.show')
        ->and($routes['GET /admin/photos2']['name'])->toBe('photos2.index');
});

it('registers singleton and array resource forms', function () {
    $routes = formsRoutes();

    expect($routes)->toHaveKeys(['GET /profile', 'GET /profile/edit', 'PUT /profile', 'PATCH /profile'])
        ->and($routes)->not->toHaveKey('POST /profile')
        ->and($routes)->toHaveKeys(['POST /profile2', 'DELETE /profile2', 'GET /profile2/create'])
        ->and($routes)->toHaveKeys(['POST /profile3', 'DELETE /profile3'])
        ->and($routes)->not->toHaveKey('GET /profile3/edit')
        ->and($routes)->toHaveKeys(['GET /widgets', 'GET /widgets/create'])
        ->and($routes)->toHaveKeys(['GET /tags', 'POST /labels'])
        ->and($routes)->not->toHaveKey('DELETE /tags/{tag}');
});

it('emits view, redirect and fallback routes with a kind', function () {
    $routes = formsRoutes();

    expect($routes['GET /welcome']['kind'])->toBe('view')
        ->and($routes['GET /welcome']['name'])->toBe('welcome')
        ->and($routes['ANY /old']['kind'])->toBe('redirect')
        ->and($routes['ANY /older']['kind'])->toBe('redirect')
        ->and($routes['GET /{fallbackPlaceholder}']['kind'])->toBe('fallback')
        ->and($routes['GET /{fallbackPlaceholder}']['controller_fqcn'])->toBe('App\Http\Controllers\HomeController')
        ->and($routes['GET /home'])->not->toHaveKey('kind');
});

it('reports an unrecognised Route call and expands it when mapped to a router method', function () {
    expect(formsUnresolved()['routes/web.php:53'])->toBe(['unknown_route_call'])
        ->and(formsRoutes())->not->toHaveKey('GET /posts');

    $mapped = formsRoutes(['softDeletableResource' => 'resource']);

    expect($mapped)->toHaveKey('GET /posts/{post}')
        ->and(formsUnresolved(['softDeletableResource' => 'resource']))->not->toHaveKey('routes/web.php:53');
});

it('applies withoutMiddleware on the route and on groups', function () {
    $routes = formsRoutes();

    expect($routes['GET /health']['middleware'])->toBe([])
        ->and($routes['GET /group-fluent']['middleware'])->toBe(['auth'])
        ->and($routes['GET /group-array']['middleware'])->toBe(['b']);
});

it('folds Class::class concatenated with a parameter string', function () {
    expect(formsRoutes()['GET /folded']['middleware'])->toBe(['auth', 'App\Http\Controllers\HomeController:admin']);
});

it('reports non-literal uri, middleware and group attributes', function () {
    $unresolved = formsUnresolved();

    expect($unresolved['routes/web.php:54'])->toBe(['dynamic_uri'])
        ->and($unresolved['routes/web.php:55'])->toBe(['dynamic_middleware'])
        ->and($unresolved['routes/web.php:63'])->toBe(['dynamic_group_attribute'])
        ->and(formsRoutes())->toHaveKey('GET /in-dynamic');
});

it('reports controllers that declare routes with attributes', function () {
    expect(formsUnresolved()['app/Http/Controllers/AttributedController.php:11'])->toBe(['attribute_route']);
});
