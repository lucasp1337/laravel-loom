<?php

declare(strict_types=1);

use Lucasp\Loom\Dto\RouteFileReference;
use Lucasp\Loom\Scanners\Visitors\ProviderListVisitor;
use Lucasp\Loom\Scanners\Visitors\RouteFileLoadVisitor;
use Lucasp\Loom\Support\RouteFileLoader;
use Lucasp\Loom\Support\RoutePathProblem;
use Lucasp\Loom\Support\RoutePathResolver;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;

function traverseWith(string $source, NodeVisitorAbstract $visitor): void
{
    $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse($source);
    expect($ast)->not->toBeNull();

    $traverser = new NodeTraverser;
    $traverser->addVisitor(new NameResolver);
    $traverser->addVisitor($visitor);
    $traverser->traverse($ast);
}

/** @return list<RouteFileReference> */
function loadReferences(string $php): array
{
    $visitor = new RouteFileLoadVisitor;
    traverseWith('<?php namespace App; use Illuminate\Support\Facades\Route; '.$php, $visitor);

    return $visitor->getReferences();
}

/**
 * Resolve every reference in $php against a source file at /app/Providers/P.php under root /app.
 *
 * @return list<string|RoutePathProblem>
 */
function resolveAll(string $php): array
{
    $resolver = new RoutePathResolver;

    return array_map(
        fn (RouteFileReference $r): string|RoutePathProblem => $resolver->resolve($r, '/app/Providers/P.php', '/app'),
        loadReferences($php),
    );
}

it('collects loadRoutesFrom on $this only', function () {
    $refs = loadReferences('class P { function boot() { $this->loadRoutesFrom(__DIR__.\'/a.php\'); $other->loadRoutesFrom(\'b.php\'); } }');

    expect($refs)->toHaveCount(1)->and($refs[0]->loader)->toBe(RouteFileLoader::LOAD_ROUTES_FROM);
});

it('collects Route::group paths in the static, fluent and array forms and skips closures', function () {
    $refs = loadReferences(<<<'PHP'
    Route::group(['prefix' => 'a'], __DIR__.'/a.php');
    Route::group(['prefix' => 'b'], function () {});
    Route::middleware('web')->prefix('c')->group(__DIR__.'/c.php');
    Route::middleware('web')->group(fn () => null);
    Route::group([], [__DIR__.'/d.php', __DIR__.'/e.php', function () {}]);
    $router->group([], __DIR__.'/f.php');
    $collection->group('name');
    PHP);

    expect($refs)->toHaveCount(4);
    expect(array_map(fn (RouteFileReference $r): RouteFileLoader => $r->loader, $refs))->each->toBe(RouteFileLoader::ROUTE_GROUP);
});

it('reads withRouting by name and by position, and withCommands', function () {
    $refs = loadReferences(<<<'PHP'
    $b->withRouting(web: __DIR__.'/w.php', api: [__DIR__.'/a.php'], commands: __DIR__.'/c.php', channels: __DIR__.'/ch.php', health: '/up');
    $b->withRouting(null, __DIR__.'/pw.php');
    $b->withCommands([__DIR__.'/cmd.php', __DIR__.'/../Commands']);
    PHP);

    expect(array_map(fn (RouteFileReference $r): string => $r->loader->value, $refs))->toBe([
        'withRouting()', 'withRouting()', 'withRouting()', 'withRouting()', 'withCommands()', 'withCommands()',
    ]);
    expect(array_map(fn (RouteFileReference $r): bool => $r->allowsDirectory, $refs))
        ->toBe([false, false, true, false, true, true]);
});

it('resolves __DIR__, base_path, app_path, concatenation and dirname', function () {
    $resolved = resolveAll(<<<'PHP'
    Route::group([], [
        __DIR__.'/../routes/x.php',
        base_path('routes/y.php'),
        base_path().'/routes/z.php',
        app_path('Http/routes.php'),
        dirname(__DIR__).DIRECTORY_SEPARATOR.'routes'.DIRECTORY_SEPARATOR.'w.php',
        dirname(__DIR__, 2).'/v.php',
        '/app/routes/abs.php',
    ]);
    PHP);
    $sep = DIRECTORY_SEPARATOR;
    $inside = fn (string $p): string => str_replace('/', $sep, $p);

    // dirname(__DIR__, 2) of /app/Providers is "/", outside the /app root.
    expect($resolved)->toBe([
        $inside('/app/routes/x.php'),
        $inside('/app/routes/y.php'),
        $inside('/app/routes/z.php'),
        $inside('/app/app/Http/routes.php'),
        $inside('/app/routes/w.php'),
        RoutePathProblem::OUTSIDE_ROOT,
        $inside('/app/routes/abs.php'),
    ]);
});

it('classifies what it cannot resolve', function () {
    $resolved = resolveAll(<<<'PHP'
    Route::group([], [
        $path,
        __DIR__.'/'.$name.'.php',
        $this->path(),
        'routes/rel.php',
        config('x').'/a.php',
        __DIR__.'/../../../outside.php',
        \Foo\base_path('x'),
    ]);
    PHP);

    expect($resolved)->toBe([
        RoutePathProblem::DYNAMIC,
        RoutePathProblem::DYNAMIC,
        RoutePathProblem::DYNAMIC,
        RoutePathProblem::RELATIVE,
        RoutePathProblem::DYNAMIC,
        RoutePathProblem::OUTSIDE_ROOT,
        RoutePathProblem::DYNAMIC,
    ]);
});

it('reads provider lists from a returned list and from config providers', function () {
    $list = new ProviderListVisitor;
    traverseWith('<?php use App\Providers\A; return [A::class, \Modules\B\P::class];', $list);

    $config = new ProviderListVisitor;
    traverseWith(<<<'PHP'
    <?php
    use App\Providers\A;
    return [
        'name' => 'x',
        'aliases' => [Illuminate\Support\Facades\Route::class],
        'providers' => ServiceProvider::defaultProviders()->merge([A::class, \Modules\B\P::class])->toArray(),
    ];
    PHP, $config);

    expect($list->getProviders())->toBe(['App\Providers\A', 'Modules\B\P'])
        ->and($config->getProviders())->toBe(['App\Providers\A', 'Modules\B\P']);
});
