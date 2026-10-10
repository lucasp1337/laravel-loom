<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Lucasp\Loom\Index\IndexBuilder;
use Lucasp\Loom\Scanners\DefaultScanners;
use Lucasp\Loom\Support\AstWalker;
use Lucasp\Loom\Support\RouteFileDiscovery;
use Lucasp\Loom\Support\RouteGroupAttribute;
use Lucasp\Loom\Support\ScanScope;
use Symfony\Component\Console\Output\BufferedOutput;

function groupsRoot(): string
{
    return dirname(__DIR__).'/Fixtures/route-groups-fixture-app';
}

/**
 * Routes of the fixture app keyed by "file METHOD uri".
 *
 * @return array<string, array<string, mixed>>
 */
function groupRoutes(?ScanScope $scope = null): array
{
    $builder = new IndexBuilder;
    DefaultScanners::registerOn($builder, $scope ?? new ScanScope);
    $routes = [];

    foreach ($builder->build(groupsRoot(), '12.x')->toArray()['routes'] as $route) {
        $routes[$route['file'].' '.$route['method'].' '.$route['uri']] = $route;
    }

    return $routes;
}

it('applies the loading group prefix, name prefix and middleware to a file loaded from its closure', function () {
    $route = groupRoutes()['Modules/Shop/routes.php GET /shop/cart'];

    expect($route['name'])->toBe('shop.cart')
        ->and($route['middleware'])->toBe(['web', 'auth'])
        ->and($route['controller_fqcn'])->toBe('App\Http\Controllers\CartController');
});

it('merges groups declared inside the loaded file after the loading group', function () {
    $route = groupRoutes()['Modules/Shop/routes.php POST /shop/admin/items'];

    expect($route['name'])->toBe('shop.admin.items')
        ->and($route['middleware'])->toBe(['web']);
});

it('applies the group of a ->group(path) call', function () {
    $route = groupRoutes()['Modules/Account/routes.php GET /account/profile'];

    expect($route['middleware'])->toBe(['auth', 'verified']);
});

it('applies the array-config Route::group(attributes, path) form', function () {
    $route = groupRoutes()['Modules/Legacy/routes.php GET /legacy/old'];

    expect($route['name'])->toBe('legacy.old')
        ->and($route['middleware'])->toBe(['throttle:60,1']);
});

it('binds bare action names to the loading group controller', function () {
    $route = groupRoutes()['Modules/Reports/routes.php GET /reports/daily'];

    expect($route['controller_fqcn'])->toBe('App\Http\Controllers\ReportController')
        ->and($route['controller_method'])->toBe('daily');
});

it('wraps withRouting web files in the web middleware group only', function () {
    $routes = groupRoutes();

    expect($routes['routes/web.php GET /home']['middleware'])->toBe(['web', 'verified'])
        ->and($routes['routes/web.php GET /inner/panel']['middleware'])->toBe(['web', 'can:manage'])
        ->and($routes['routes/web.php GET /inner/panel']['name'])->toBe('inner.panel');
});

it('wraps withRouting api files in the api group and the literal apiPrefix', function () {
    $route = groupRoutes()['routes/api.php GET /api/v1/ping'];

    expect($route['middleware'])->toBe(['api'])
        ->and($route['name'])->toBe('api.ping');
});

it('composes contexts through a file loaded by a loaded file', function () {
    $route = groupRoutes()['Modules/Deep/routes.php GET /deep/leaf'];

    expect($route['middleware'])->toBe(['web']);
});

it('emits a file loaded under two groups once per group', function () {
    $routes = groupRoutes();

    expect($routes)->toHaveKeys(['Modules/Shared/routes.php GET /v1/ping', 'Modules/Shared/routes.php GET /v2/ping']);
});

it('records nothing for an unresolvable prefix and reports it', function () {
    $routes = groupRoutes();

    expect($routes['Modules/Dynamic/routes.php GET /thing']['middleware'])->toBe(['web']);

    $found = array_map(
        fn ($a): string => substr($a->file, strlen(groupsRoot()) + 1).':'.$a->line.' '.$a->attribute->value,
        (new RouteFileDiscovery(new ScanScope, new AstWalker))->unresolvedAttributes(groupsRoot()),
    );

    expect($found)->toBe(['app/Providers/ShopServiceProvider.php:24 '.RouteGroupAttribute::PREFIX->value]);
});

it('leaves routes ungrouped when route discovery is off', function () {
    $routes = groupRoutes(new ScanScope(['app'], [], ['routes'], false));

    expect($routes['routes/web.php GET /home']['middleware'])->toBe(['verified'])
        ->and($routes['routes/api.php GET /ping']['middleware'])->toBe([]);
});

it('prints group attributes not applied under -v only', function () {
    app()->setBasePath(groupsRoot());
    config()->set('loom.index_path', sys_get_temp_dir().'/loom-groups-'.bin2hex(random_bytes(6)).'/index.json');

    $quiet = new BufferedOutput(BufferedOutput::VERBOSITY_NORMAL);
    Artisan::call('loom:scan', [], $quiet);
    $text = $quiet->fetch();

    expect($text)->toContain('unresolved group attributes: 1 (-v lists them)')
        ->and($text)->not->toContain('Group attributes not applied:');

    $verbose = new BufferedOutput(BufferedOutput::VERBOSITY_VERBOSE);
    Artisan::call('loom:scan', [], $verbose);

    expect($verbose->fetch())->toContain('app/Providers/ShopServiceProvider.php:24  Route::group(): group prefix is not statically resolvable');
});
