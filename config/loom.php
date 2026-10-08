<?php

declare(strict_types=1);

return [
    // Snapshot written by loom:scan and read by the CLI, MCP server and UI;
    // null means storage/loom/index.json.
    'index_path' => env('LOOM_INDEX_PATH'),

    'scan' => [
        // Directories loom:scan walks, relative to the project root. `*` globs
        // are allowed (e.g. 'Modules/*'). Convention directories (Events,
        // Listeners, Jobs, Mail, Notifications) resolve inside each one.
        'paths' => ['app'],

        // Directories holding route files, relative to the project root. `*`
        // globs are allowed (e.g. 'Modules/*/routes'). Route files are read
        // for `routes[]` and for dispatches inside route closures.
        'route_paths' => ['routes'],

        // Also scan every directory in composer.json's autoload.psr-4.
        'psr4_paths' => false,

        // Globs relative to the project root; matching files and everything
        // under matching directories are skipped by every scanner.
        'exclude' => [],
    ],

    'ui' => [
        // Kill switch. The UI needs livewire/livewire and is also mounted only
        // in the environments below.
        'enabled' => env('LOOM_UI_ENABLED', true),

        // App environments that mount the UI. In any other environment no
        // route, asset, view or gate exists (plain 404).
        'environments' => ['local'],

        // Listing `production` above is ignored unless this is true.
        'allow_in_production' => false,

        // URI prefix and optional domain the UI is served under.
        'path' => env('LOOM_PATH', 'loom'),
        'domain' => env('LOOM_DOMAIN'),

        // Applied before the built-in `viewLoom` gate check, which always runs.
        'middleware' => ['web'],

        // Optional UI-only override of the top-level `index_path`.
        'index_path' => null,

        // Default chain depth on the chain page (clamped to 1-6).
        'chain_depth' => 3,
    ],

    'mcp' => [
        // Turns off `loom:mcp` even when laravel/mcp is installed.
        'enabled' => env('LOOM_MCP_ENABLED', true),
    ],
];
