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
// Group / prefix (slice 2: prefix, name prefix, default controller applied)
// ---------------------------------------------------------------------------

it('applies a group prefix to a nested route', function () {
    $entries = routeEntries();

    // Slice 2 prepends the group prefix: the uri becomes '/admin/panel'.
    $entry = routeBy($entries, 'GET', '/admin/panel');

    expect($entry)->not->toBeNull();
    expect($entry->controllerFqcn)->toBe('App\\Http\\Controllers\\AdminController');
    expect($entry->controllerMethod)->toBe('panel');

    // The bare leaf uri no longer appears.
    expect(routeBy($entries, 'GET', '/panel'))->toBeNull();
});

it('collapses a root leaf in a prefix group to the prefix', function () {
    $entry = routeBy(routeEntries(), 'GET', '/admin');

    expect($entry)->not->toBeNull();
    expect($entry->controllerFqcn)->toBe('App\\Http\\Controllers\\AdminController');
    expect($entry->controllerMethod)->toBe('dashboard');
    expect($entry->name)->toBeNull();
});

it('applies a fluent prefix, name prefix, and default controller', function () {
    $entry = routeBy(routeEntries(), 'GET', '/api/v1/status');

    expect($entry)->not->toBeNull();
    expect($entry->name)->toBe('v1.status');
    expect($entry->controllerFqcn)->toBe('App\\Http\\Controllers\\UserController');
    expect($entry->controllerMethod)->toBe('store');
});

it('applies an array-config group prefix, name prefix, and controller', function () {
    $entry = routeBy(routeEntries(), 'GET', '/dash/home');

    expect($entry)->not->toBeNull();
    expect($entry->name)->toBe('dash.home');
    expect($entry->controllerFqcn)->toBe('App\\Http\\Controllers\\HomeController');
    expect($entry->controllerMethod)->toBe('index');
});

it('accumulates nested group prefixes and name prefixes', function () {
    $entry = routeBy(routeEntries(), 'GET', '/v2/users/{id}');

    expect($entry)->not->toBeNull();
    expect($entry->name)->toBe('users.show');
    expect($entry->controllerFqcn)->toBe('App\\Http\\Controllers\\UserController');
    expect($entry->controllerMethod)->toBe('show');
});

it('leaves ungrouped routes unchanged by slice-2 group resolution', function () {
    $entries = routeEntries();

    $users = routeBy($entries, 'POST', '/users');
    expect($users)->not->toBeNull();
    expect($users->uri)->toBe('/users');

    $home = routeBy($entries, 'GET', '/');
    expect($home)->not->toBeNull();
    expect($home->uri)->toBe('/');
    expect($home->name)->toBe('home');
});

// ---------------------------------------------------------------------------
// Middleware (slice 3)
// ---------------------------------------------------------------------------

it('resolves a route-level single middleware string', function () {
    $entry = routeBy(routeEntries(), 'GET', '/dashboard');

    expect($entry)->not->toBeNull();
    expect($entry->middleware)->toBe(['auth']);
});

it('resolves a route-level middleware array', function () {
    $entry = routeBy(routeEntries(), 'GET', '/reports');

    expect($entry)->not->toBeNull();
    expect($entry->middleware)->toBe(['auth', 'verified']);
});

it('accumulates chained ->middleware() calls in source order', function () {
    $entry = routeBy(routeEntries(), 'GET', '/billing');

    expect($entry)->not->toBeNull();
    expect($entry->middleware)->toBe(['auth', 'throttle:60,1']);
});

it('prepends fluent group middleware before route-level middleware', function () {
    $entry = routeBy(routeEntries(), 'GET', '/account/settings');

    expect($entry)->not->toBeNull();
    expect($entry->middleware)->toBe(['web', 'auth', 'verified']);
});

it('resolves array-config group middleware', function () {
    $entry = routeBy(routeEntries(), 'GET', '/secure/audit');

    expect($entry)->not->toBeNull();
    expect($entry->middleware)->toBe(['auth', 'can:admin']);
});

it('resolves a ::class middleware reference to its FQCN', function () {
    $entry = routeBy(routeEntries(), 'GET', '/classmw');

    expect($entry)->not->toBeNull();
    expect($entry->middleware)->toBe(['App\\Http\\Middleware\\EnsureActive']);
});

it('deduplicates exact-duplicate middleware across group and route level', function () {
    $entry = routeBy(routeEntries(), 'GET', '/dup/x');

    expect($entry)->not->toBeNull();
    expect($entry->middleware)->toBe(['auth']);
});

it('emits an empty middleware list for an ungrouped route with no middleware', function () {
    $entries = routeEntries();

    $home = routeBy($entries, 'GET', '/');
    expect($home)->not->toBeNull();
    expect($home->middleware)->toBe([]);

    $users = routeBy($entries, 'POST', '/users');
    expect($users)->not->toBeNull();
    expect($users->middleware)->toBe([]);
});

// ---------------------------------------------------------------------------
// Resource expansion (slice 4)
// ---------------------------------------------------------------------------

it('expands Route::resource into its seven constituent routes', function () {
    $entries = routeEntries();

    $expected = [
        ['GET', '/photos', 'photos.index', 'index'],
        ['GET', '/photos/create', 'photos.create', 'create'],
        ['POST', '/photos', 'photos.store', 'store'],
        ['GET', '/photos/{photo}', 'photos.show', 'show'],
        ['GET', '/photos/{photo}/edit', 'photos.edit', 'edit'],
        ['PUT', '/photos/{photo}', 'photos.update', 'update'],
        ['DELETE', '/photos/{photo}', 'photos.destroy', 'destroy'],
    ];

    foreach ($expected as [$method, $uri, $name, $action]) {
        $entry = routeBy($entries, $method, $uri);

        expect($entry)->not->toBeNull();
        expect($entry->name)->toBe($name);
        expect($entry->controllerMethod)->toBe($action);
        expect($entry->controllerFqcn)->toBe('App\\Http\\Controllers\\PhotoController');
        expect($entry->middleware)->toBe([]);
    }

    $photos = array_values(array_filter(
        $entries,
        static fn (RouteEntry $e): bool => $e->controllerFqcn === 'App\\Http\\Controllers\\PhotoController',
    ));

    expect($photos)->toHaveCount(7);
});

it('expands Route::apiResource into five routes with no create or edit', function () {
    $entries = routeEntries();

    $expected = [
        ['GET', '/widgets', 'widgets.index', 'index'],
        ['POST', '/widgets', 'widgets.store', 'store'],
        ['GET', '/widgets/{widget}', 'widgets.show', 'show'],
        ['PUT', '/widgets/{widget}', 'widgets.update', 'update'],
        ['DELETE', '/widgets/{widget}', 'widgets.destroy', 'destroy'],
    ];

    foreach ($expected as [$method, $uri, $name, $action]) {
        $entry = routeBy($entries, $method, $uri);

        expect($entry)->not->toBeNull();
        expect($entry->name)->toBe($name);
        expect($entry->controllerMethod)->toBe($action);
        expect($entry->controllerFqcn)->toBe('App\\Http\\Controllers\\WidgetController');
    }

    $widgets = array_values(array_filter(
        $entries,
        static fn (RouteEntry $e): bool => $e->controllerFqcn === 'App\\Http\\Controllers\\WidgetController',
    ));

    expect($widgets)->toHaveCount(5);

    expect(routeBy($entries, 'GET', '/widgets/create'))->toBeNull();
    expect(array_filter(
        $entries,
        static fn (RouteEntry $e): bool => $e->name === 'widgets.create',
    ))->toBe([]);
});

it('honours ->only() on a resource', function () {
    $entries = routeEntries();

    $books = array_values(array_filter(
        $entries,
        static fn (RouteEntry $e): bool => $e->controllerFqcn === 'App\\Http\\Controllers\\BookController',
    ));

    expect($books)->toHaveCount(2);

    $index = routeBy($entries, 'GET', '/books');
    expect($index)->not->toBeNull();
    expect($index->name)->toBe('books.index');
    expect($index->controllerMethod)->toBe('index');

    $show = routeBy($entries, 'GET', '/books/{book}');
    expect($show)->not->toBeNull();
    expect($show->name)->toBe('books.show');
    expect($show->controllerMethod)->toBe('show');

    expect(routeBy($entries, 'POST', '/books'))->toBeNull();
});

it('honours ->except() on a resource', function () {
    $entries = routeEntries();

    $tags = array_values(array_filter(
        $entries,
        static fn (RouteEntry $e): bool => $e->controllerFqcn === 'App\\Http\\Controllers\\TagController',
    ));

    expect($tags)->toHaveCount(4);

    foreach (['index', 'store', 'show', 'update'] as $action) {
        $match = array_values(array_filter(
            $tags,
            static fn (RouteEntry $e): bool => $e->controllerMethod === $action,
        ));
        expect($match)->toHaveCount(1);
    }

    foreach (['create', 'edit', 'destroy'] as $action) {
        expect(array_filter(
            $tags,
            static fn (RouteEntry $e): bool => $e->controllerMethod === $action,
        ))->toBe([]);
    }

    expect(routeBy($entries, 'DELETE', '/tags/{tag}'))->toBeNull();
});

it('singularises the resource parameter for the route uri', function () {
    $entry = routeBy(routeEntries(), 'GET', '/categories/{category}');

    expect($entry)->not->toBeNull();
    expect($entry->name)->toBe('categories.show');
    expect($entry->controllerMethod)->toBe('show');
    expect($entry->controllerFqcn)->toBe('App\\Http\\Controllers\\CategoryController');
});

it('applies group prefix, name, and middleware to a grouped apiResource', function () {
    $entries = routeEntries();

    $items = array_values(array_filter(
        $entries,
        static fn (RouteEntry $e): bool => $e->controllerFqcn === 'App\\Http\\Controllers\\ItemController',
    ));

    expect($items)->toHaveCount(5);

    $index = routeBy($entries, 'GET', '/admin/items');
    expect($index)->not->toBeNull();
    expect($index->name)->toBe('admin.items.index');
    expect($index->controllerMethod)->toBe('index');
    expect($index->middleware)->toBe(['auth']);

    $show = routeBy($entries, 'GET', '/admin/items/{item}');
    expect($show)->not->toBeNull();
    expect($show->name)->toBe('admin.items.show');
    expect($show->controllerMethod)->toBe('show');
    expect($show->middleware)->toBe(['auth']);

    foreach ($items as $item) {
        expect($item->middleware)->toBe(['auth']);
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

it('applies group context to routes in routes/api.php', function () {
    $entry = routeBy(routeEntries(), 'GET', '/v1/ping');

    expect($entry)->not->toBeNull();
    expect($entry->name)->toBe('api.v1.ping');
    expect($entry->controllerFqcn)->toBe('App\\Http\\Controllers\\Api\\UserController');
    expect($entry->controllerMethod)->toBe('index');
});

// ---------------------------------------------------------------------------
// file:line emission
// ---------------------------------------------------------------------------

it('reports the source file and line for a known route', function () {
    $entry = routeBy(routeEntries(), 'GET', '/');

    expect($entry)->not->toBeNull();

    $file = str_replace(DIRECTORY_SEPARATOR, '/', $entry->file);

    expect($file)->toBe('routes/web.php');
    expect($entry->line)->toBe(17);
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
// Dispatch attribution is a cross-link concern (slice 5)
// ---------------------------------------------------------------------------

it('leaves dispatches empty at scan time for a dispatching controller route', function () {
    // The raw RouteScanner never runs the cross-link pass, so even a route whose
    // controller method dispatches an event and a job emits dispatches = [].
    $entry = routeBy(routeEntries(), 'GET', '/checkout');

    expect($entry)->not->toBeNull();
    expect($entry->controllerFqcn)->toBe('App\\Http\\Controllers\\UserController');
    expect($entry->controllerMethod)->toBe('checkout');
    expect($entry->name)->toBe('checkout');
    expect($entry->dispatches)->toBe([]);
});

it('leaves dispatches empty at scan time for every route entry', function () {
    foreach (routeEntries() as $entry) {
        expect($entry->dispatches)->toBe([]);
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
