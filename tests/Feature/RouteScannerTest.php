<?php

declare(strict_types=1);

use Lucasp\Loom\Dto\RouteEntry;
use Lucasp\Loom\Scanners\RouteScanner;

function routeFixturePath(): string
{
    return dirname(__DIR__).'/Fixtures/route-fixture-app';
}

/**
 * Locate a route entry by its (method, uri) tuple.
 *
 * @param  list<RouteEntry>  $entries
 */
function routeBy(array $entries, string $method, string $uri): ?RouteEntry
{
    foreach ($entries as $entry) {
        if ($entry->method === $method && $entry->uri === $uri) {
            return $entry;
        }
    }

    return null;
}

/**
 * Convenience: scan the fixture and return the `routes[]` list.
 *
 * @return list<RouteEntry>
 */
function routeEntries(): array
{
    $result = (new RouteScanner)->scan(routeFixturePath());

    /** @var list<RouteEntry> $entries */
    $entries = $result['routes'];

    return $entries;
}

// ---------------------------------------------------------------------------
// Empty-path behaviour
// ---------------------------------------------------------------------------

it('returns an empty routes array when routes/ is absent', function () {
    $result = (new RouteScanner)->scan(sys_get_temp_dir());

    expect($result)->toBe(['routes' => []]);
});

// ---------------------------------------------------------------------------
// Action forms
// ---------------------------------------------------------------------------

it('resolves an array action plus name', function () {
    $entry = routeBy(routeEntries(), 'GET', '/');

    expect($entry)->not->toBeNull();
    expect($entry->controllerFqcn)->toBe('App\\Http\\Controllers\\HomeController');
    expect($entry->controllerMethod)->toBe('index');
    expect($entry->name)->toBe('home');
});

it('leaves name null on an array action with no ->name()', function () {
    $entry = routeBy(routeEntries(), 'POST', '/users');

    expect($entry)->not->toBeNull();
    expect($entry->controllerFqcn)->toBe('App\\Http\\Controllers\\UserController');
    expect($entry->controllerMethod)->toBe('store');
    expect($entry->name)->toBeNull();
});

it('resolves a bare invokable controller to __invoke', function () {
    $entry = routeBy(routeEntries(), 'GET', '/invoke');

    expect($entry)->not->toBeNull();
    expect($entry->controllerFqcn)->toBe('App\\Http\\Controllers\\DashboardController');
    expect($entry->controllerMethod)->toBe('__invoke');
});

it('resolves a single-element array action to __invoke', function () {
    $entry = routeBy(routeEntries(), 'GET', '/invoke-array');

    expect($entry)->not->toBeNull();
    expect($entry->controllerFqcn)->toBe('App\\Http\\Controllers\\DashboardController');
    expect($entry->controllerMethod)->toBe('__invoke');
});

it('resolves a legacy "Controller@method" string action', function () {
    $entry = routeBy(routeEntries(), 'PUT', '/legacy');

    expect($entry)->not->toBeNull();
    expect($entry->controllerFqcn)->toBe('App\\Http\\Controllers\\UserController');
    expect($entry->controllerMethod)->toBe('update');
});

it('leaves controller null and method null for a closure action', function () {
    $entry = routeBy(routeEntries(), 'GET', '/closure');

    expect($entry)->not->toBeNull();
    expect($entry->controllerFqcn)->toBeNull();
    expect($entry->controllerMethod)->toBeNull();
});

// ---------------------------------------------------------------------------
// Verb forms
// ---------------------------------------------------------------------------

it('expands Route::match into one entry per verb', function () {
    $entries = routeEntries();

    $get = routeBy($entries, 'GET', '/multi');
    $post = routeBy($entries, 'POST', '/multi');

    expect($get)->not->toBeNull();
    expect($post)->not->toBeNull();

    $multi = array_values(array_filter(
        $entries,
        static fn (RouteEntry $e): bool => $e->uri === '/multi',
    ));

    expect($multi)->toHaveCount(2);

    foreach ([$get, $post] as $entry) {
        expect($entry->controllerFqcn)->toBe('App\\Http\\Controllers\\UserController');
        expect($entry->controllerMethod)->toBe('multi');
    }
});

it('emits a single ANY-method entry for Route::any', function () {
    $entries = routeEntries();

    $any = array_values(array_filter(
        $entries,
        static fn (RouteEntry $e): bool => $e->uri === '/any',
    ));

    expect($any)->toHaveCount(1);
    expect($any[0]->method)->toBe('ANY');
    expect($any[0]->controllerFqcn)->toBe('App\\Http\\Controllers\\UserController');
    expect($any[0]->controllerMethod)->toBe('any');
});

// ---------------------------------------------------------------------------
// Group / prefix (slice 1: no prefix application)
// ---------------------------------------------------------------------------

it('discovers a route nested in a prefix group without applying the prefix', function () {
    $entries = routeEntries();

    // Slice 1 does not resolve the group prefix: the uri stays '/panel'.
    $entry = routeBy($entries, 'GET', '/panel');

    expect($entry)->not->toBeNull();
    expect($entry->controllerFqcn)->toBe('App\\Http\\Controllers\\AdminController');
    expect($entry->controllerMethod)->toBe('panel');

    // The group/prefix chain itself emits nothing.
    expect(routeBy($entries, 'GET', '/admin/panel'))->toBeNull();
});

// ---------------------------------------------------------------------------
// Resource (slice 1: emits nothing)
// ---------------------------------------------------------------------------

it('emits no entries for Route::resource in slice 1', function () {
    $entries = routeEntries();

    foreach ($entries as $entry) {
        expect($entry->uri)->not->toBe('photos');
        expect($entry->controllerFqcn)->not->toBe('App\\Http\\Controllers\\PhotoController');
    }
});

// ---------------------------------------------------------------------------
// Multi-file scan
// ---------------------------------------------------------------------------

it('discovers routes from routes/api.php as well as routes/web.php', function () {
    $entries = routeEntries();

    $api = routeBy($entries, 'GET', '/api/users');

    expect($api)->not->toBeNull();
    expect($api->controllerFqcn)->toBe('App\\Http\\Controllers\\Api\\UserController');
    expect($api->controllerMethod)->toBe('index');
    expect($api->name)->toBe('api.users.index');

    $show = routeBy($entries, 'GET', '/api/users/{user}');

    expect($show)->not->toBeNull();
    expect($show->controllerMethod)->toBe('show');
});

// ---------------------------------------------------------------------------
// file:line emission
// ---------------------------------------------------------------------------

it('reports the source file and line for a known route', function () {
    $entry = routeBy(routeEntries(), 'GET', '/');

    expect($entry)->not->toBeNull();

    $file = str_replace(DIRECTORY_SEPARATOR, '/', $entry->file);

    expect($file)->toBe('routes/web.php');
    expect($entry->line)->toBe(12);
});

// ---------------------------------------------------------------------------
// DTO + portability
// ---------------------------------------------------------------------------

it('emits every entry as a RouteEntry DTO', function () {
    $entries = routeEntries();

    expect($entries)->not->toBe([]);

    foreach ($entries as $entry) {
        expect($entry)->toBeInstanceOf(RouteEntry::class);
    }
});

it('reports file paths relative to the fixture root with forward slashes', function () {
    $entries = routeEntries();

    expect($entries)->not->toBe([]);

    foreach ($entries as $entry) {
        $file = str_replace(DIRECTORY_SEPARATOR, '/', $entry->file);

        expect($file)->not->toContain('\\');
        expect($file)->not->toStartWith('/');
        expect(str_starts_with($file, 'routes/'))->toBeTrue();
    }
});

// ---------------------------------------------------------------------------
// Sorting
// ---------------------------------------------------------------------------

it('sorts entries by (file, line, method) ascending', function () {
    $entries = routeEntries();

    $tuples = array_map(
        static fn (RouteEntry $e): array => [
            str_replace(DIRECTORY_SEPARATOR, '/', $e->file),
            $e->line,
            $e->method,
        ],
        $entries,
    );

    $sorted = $tuples;
    usort($sorted, static function (array $a, array $b): int {
        return [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]];
    });

    expect($tuples)->toBe($sorted);
});
