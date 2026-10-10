<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Lucasp\Loom\Index\IndexBuilder;
use Lucasp\Loom\Scanners\DefaultScanners;
use Lucasp\Loom\Support\AstWalker;
use Lucasp\Loom\Support\RouteFileDiscovery;
use Lucasp\Loom\Support\RoutePathProblem;
use Lucasp\Loom\Support\ScanScope;
use Symfony\Component\Console\Output\BufferedOutput;

const DISCOVERY_FIXTURE = 'route-discovery-fixture-app';

function discoveryRoot(): string
{
    return dirname(__DIR__).'/Fixtures/'.DISCOVERY_FIXTURE;
}

/**
 * @return array<string, mixed>
 */
function discoveryScan(ScanScope $scope): array
{
    $builder = new IndexBuilder;
    DefaultScanners::registerOn($builder, $scope);

    return $builder->build(discoveryRoot(), '12.x')->toArray();
}

/**
 * @return list<string>
 */
function discoveredFiles(ScanScope $scope): array
{
    $discovery = new RouteFileDiscovery($scope, new AstWalker);
    $root = discoveryRoot().DIRECTORY_SEPARATOR;
    $files = array_map(fn (SplFileInfo $f): string => str_replace([$root, DIRECTORY_SEPARATOR], ['', '/'], $f->getPathname()), $discovery->files(discoveryRoot()));
    sort($files);

    return $files;
}

it('follows loadRoutesFrom, Route group paths and withRouting with no route_paths config', function () {
    expect(discoveredFiles(new ScanScope))->toBe([
        'Modules/Admin/routes/api.php',
        'Modules/Admin/routes/console.php',
        'Modules/Admin/routes/web.php',
        'Modules/Billing/routes/nested.php',
        'Modules/Billing/routes/web.php',
        'Modules/Shop/routes/legacy.php',
        'Modules/Shop/routes/shop.php',
        'Modules/Shop/routes/skipped.php',
        'routes/web.php',
    ]);
});

it('emits routes from discovered files', function () {
    $payload = discoveryScan(new ScanScope);

    $uris = array_map(fn (array $route): string => $route['file'].' '.$route['uri'], $payload['routes']);
    sort($uris);

    expect($uris)->toBe([
        'Modules/Admin/routes/api.php /admin/stats',
        'Modules/Admin/routes/web.php /admin',
        'Modules/Billing/routes/nested.php /invoices',
        'Modules/Billing/routes/web.php /billing',
        'Modules/Shop/routes/legacy.php /legacy',
        'Modules/Shop/routes/shop.php /shop',
        'Modules/Shop/routes/skipped.php /skipped',
        'routes/web.php /home',
    ]);
});

it('dispatches inside a discovered route closure are linked to the event', function () {
    $payload = discoveryScan(new ScanScope);

    $event = collect($payload['events'])->firstWhere('fqcn', 'App\Events\ModuleHit');

    expect($event)->not->toBeNull()
        ->and(array_column($event['dispatched_from'], 'file'))->toBe(['Modules/Admin/routes/web.php']);
});

it('lists paths it could not follow, with a reason', function () {
    $discovery = new RouteFileDiscovery(new ScanScope, new AstWalker);

    $found = array_map(
        fn ($path): string => str_replace(DIRECTORY_SEPARATOR, '/', substr($path->file, strlen(discoveryRoot()) + 1)).':'.$path->line.' '.$path->problem->value,
        $discovery->unresolved(discoveryRoot()),
    );

    expect($found)->toBe([
        'Modules/Billing/Providers/BillingServiceProvider.php:12 '.RoutePathProblem::DYNAMIC->value,
        'app/Providers/AppServiceProvider.php:20 '.RoutePathProblem::DYNAMIC->value,
        'app/Providers/AppServiceProvider.php:21 '.RoutePathProblem::RELATIVE->value,
        'app/Providers/AppServiceProvider.php:22 '.RoutePathProblem::OUTSIDE_ROOT->value,
        'bootstrap/app.php:8 '.RoutePathProblem::NOT_FOUND->value,
    ]);
});

it('scan.discover_routes=false restores route_paths-only behaviour', function () {
    $scope = new ScanScope(['app'], [], ['routes'], false);

    expect(discoveredFiles($scope))->toBe(['routes/web.php']);

    $uris = array_column(discoveryScan($scope)['routes'], 'uri');
    expect($uris)->toBe(['/home']);

    $discovery = new RouteFileDiscovery($scope, new AstWalker);
    expect($discovery->unresolved(discoveryRoot()))->toBe([]);
});

it('reads scan.discover_routes from the config array', function () {
    expect(ScanScope::fromConfig(['discover_routes' => false], discoveryRoot())->discoversRoutes())->toBeFalse()
        ->and(ScanScope::fromConfig([], discoveryRoot())->discoversRoutes())->toBeTrue();
});

it('removes excluded files from the discovered set', function () {
    $scope = new ScanScope(['app'], ['Modules/Shop/routes/skipped.php', 'Modules/Admin']);

    expect(discoveredFiles($scope))->toBe([
        'Modules/Billing/routes/nested.php',
        'Modules/Billing/routes/web.php',
        'Modules/Shop/routes/legacy.php',
        'Modules/Shop/routes/shop.php',
        'routes/web.php',
    ]);
});

it('does not search excluded files for loading calls', function () {
    $scope = new ScanScope(['app'], ['Modules/Billing/Providers', 'routes/**']);

    // Billing's provider is excluded, so its route file is no longer reached.
    expect(discoveredFiles($scope))->not->toContain('Modules/Billing/routes/web.php')
        ->and(discoveredFiles($scope))->toContain('Modules/Shop/routes/shop.php');
});

it('prints unresolved route paths under -v only', function () {
    app()->setBasePath(discoveryRoot());
    config()->set('loom.index_path', sys_get_temp_dir().'/loom-discovery-'.bin2hex(random_bytes(6)).'/index.json');

    $quiet = new BufferedOutput(BufferedOutput::VERBOSITY_NORMAL);
    Artisan::call('loom:scan', [], $quiet);
    $text = $quiet->fetch();

    expect($text)->toContain('unresolved route paths: 5 (-v lists them)')
        ->and($text)->not->toContain('Route paths not followed:');

    $verbose = new BufferedOutput(BufferedOutput::VERBOSITY_VERBOSE);
    Artisan::call('loom:scan', [], $verbose);
    $text = $verbose->fetch();

    expect($text)->toContain('Route paths not followed:')
        ->and($text)->toContain('Modules/Billing/Providers/BillingServiceProvider.php:12  loadRoutesFrom(): path is not statically resolvable')
        ->and($text)->toContain('app/Providers/AppServiceProvider.php:21  Route::group(): relative path depends on the working directory')
        ->and($text)->toContain('bootstrap/app.php:8  withRouting(): no such file');
});

it('--no-discover-routes reads only route_paths and wins over config', function () {
    $out = sys_get_temp_dir().'/loom-discovery-'.bin2hex(random_bytes(6)).'/index.json';
    app()->setBasePath(discoveryRoot());
    config()->set('loom.index_path', $out);
    config()->set('loom.scan.discover_routes', true);

    $output = new BufferedOutput(BufferedOutput::VERBOSITY_NORMAL);
    expect(Artisan::call('loom:scan', ['--no-discover-routes' => true], $output))->toBe(0);

    $index = json_decode((string) file_get_contents($out), true);

    expect(array_column($index['routes'], 'uri'))->toBe(['/home'])
        ->and($output->fetch())->not->toContain('unresolved route paths');

    Artisan::call('loom:scan', [], new BufferedOutput);
    $index = json_decode((string) file_get_contents($out), true);

    expect($index['routes'])->toHaveCount(8);
});
