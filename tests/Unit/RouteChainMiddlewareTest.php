<?php

declare(strict_types=1);

use Lucasp\Loom\Scanners\Visitors\RouteChainVisitor;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/**
 * Middleware of every route in $php, keyed by the route's URI.
 *
 * @return array<string, list<string>>
 */
function routeMiddlewareOf(string $php): array
{
    $ast = (new ParserFactory)->createForNewestSupportedVersion()
        ->parse('<?php namespace App; use Illuminate\Support\Facades\Route; '.$php);
    expect($ast)->not->toBeNull();

    $visitor = new RouteChainVisitor;
    $traverser = new NodeTraverser;
    $traverser->addVisitor(new NameResolver);
    $traverser->addVisitor($visitor);
    $traverser->traverse($ast);

    $out = [];
    foreach ($visitor->getEntries() as $entry) {
        $out[(string) $entry->rootArgs->valueAt(0)?->value] = $entry->middleware;
    }

    return $out;
}

it('keeps only the last middleware() call on a group registrar', function () {
    $routes = routeMiddlewareOf("Route::middleware('a')->middleware('b')->group(function () { Route::get('x', 'C@m'); });");

    expect($routes['x'])->toBe(['b']);
});

it('replaces a registrar middleware list that sits around other setters', function () {
    $routes = routeMiddlewareOf("Route::middleware(['a', 'b'])->prefix('p')->middleware('c')->group(function () { Route::get('x', 'C@m'); });");

    expect($routes['x'])->toBe(['c']);
});

it('replaces the registrar middleware in the array-config form too', function () {
    $routes = routeMiddlewareOf("Route::group(['middleware' => 'a', 'middleware' => 'b'], function () { Route::get('x', 'C@m'); });");

    expect($routes['x'])->toBe(['b']);
});

it('keeps middleware recorded when withoutMiddleware is chained between middleware calls', function () {
    $routes = routeMiddlewareOf("Route::middleware('a')->withoutMiddleware('x')->withoutMiddleware('y')->group(function () { Route::get('x', 'C@m'); });");

    expect($routes['x'])->toBe(['a']);
});

it('accumulates route-level middleware() calls', function () {
    $routes = routeMiddlewareOf("Route::get('x', 'C@m')->middleware('a')->middleware(['b'])->middleware('c', 'd');");

    expect($routes['x'])->toBe(['a', 'b', 'c', 'd']);
});

it('accumulates nested groups outer then inner', function () {
    $routes = routeMiddlewareOf(<<<'PHP'
        Route::middleware('a')->group(function () {
            Route::middleware('b')->group(function () {
                Route::get('x', 'C@m');
            });
            Route::get('y', 'C@m');
        });
        PHP);

    expect($routes['x'])->toBe(['a', 'b'])
        ->and($routes['y'])->toBe(['a']);
});

it('applies group, then route-level middleware after a replaced registrar chain', function () {
    $routes = routeMiddlewareOf(<<<'PHP'
        Route::middleware('a')->middleware('b')->group(function () {
            Route::get('x', 'C@m')->middleware('c')->middleware('d');
        });
        PHP);

    expect($routes['x'])->toBe(['b', 'c', 'd']);
});

it('lets a later readable middleware() replace an earlier unreadable one', function () {
    $routes = routeMiddlewareOf("Route::middleware(\$dynamic)->middleware('b')->group(function () { Route::get('x', 'C@m'); });");

    expect($routes['x'])->toBe(['b']);
});
