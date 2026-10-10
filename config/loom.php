<?php

declare(strict_types=1);

return [
    // Where loom:scan writes the index and where the CLI, MCP server and UI
    // read it. null means storage/loom/index.json.
    'index_path' => env('LOOM_INDEX_PATH'),

    'scan' => [
        // Directories loom:scan walks, relative to the project root. A `*`
        // matches within one path segment (`Modules/*`). Paths outside the
        // project root are rejected. Convention directories (Events, Listeners,
        // Jobs, Mail, Notifications) resolve inside each one.
        'paths' => ['app'],

        // Directories holding route files, relative to the project root. `*`
        // globs are allowed (`Modules/*/routes`). Read for `routes[]` and for
        // dispatches inside route closures; independent of `scan.paths`.
        'route_paths' => ['routes'],

        // Also read route files that Laravel loads from a statically resolvable
        // path: `loadRoutesFrom()`, `Route::group()` with a file path and the
        // `withRouting()` paths in bootstrap/app.php. `false` reads only
        // `scan.route_paths`; `loom:scan --no-discover-routes` does the same
        // for one run.
        'discover_routes' => true,

        // Also scan every directory in composer.json's `autoload.psr-4`.
        // `autoload-dev` is not included.
        'psr4_paths' => false,

        // Globs relative to the project root. A match removes the file, or
        // everything under the matching directory, from every scanner.
        'exclude' => [],
    ],

    'ui' => [
        // Kill switch. When false, no routes, pages or gate exist in any
        // environment. The UI is also absent without livewire/livewire.
        'enabled' => env('LOOM_UI_ENABLED', true),

        // App environments that mount the UI. Elsewhere nothing is registered
        // and the path returns 404.
        'environments' => ['local'],

        // Listing `production` in `ui.environments` is ignored unless this is
        // true. A warning is logged when it is listed but not allowed.
        'allow_in_production' => false,

        // URI prefix the UI is served under. Slashes at either end are
        // trimmed; an empty value falls back to `loom`.
        'path' => env('LOOM_PATH', 'loom'),

        // Serve the UI only on this domain. null serves every domain.
        'domain' => env('LOOM_DOMAIN'),

        // Middleware that runs before the `viewLoom` gate check, which always
        // runs.
        'middleware' => ['web'],

        // Overrides `index_path` for the UI only. A non-empty value wins.
        'index_path' => null,

        // Depth the chain view opens with. Clamped to 1-6; a non-integer
        // falls back to 3.
        'chain_depth' => 3,
    ],

    'mcp' => [
        // Turns off `loom:mcp` even when laravel/mcp is installed.
        'enabled' => env('LOOM_MCP_ENABLED', true),
    ],
];
