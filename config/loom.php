<?php

declare(strict_types=1);

return [
    // Snapshot written by loom:scan and read by the CLI, MCP server and UI;
    // null means storage/loom/index.json.
    'index_path' => env('LOOM_INDEX_PATH'),

    'ui' => [
        // Mount the read-only browser UI.
        'enabled' => env('LOOM_UI_ENABLED', true),

        // URI prefix and optional domain the UI is served under.
        'path' => env('LOOM_PATH', 'loom'),
        'domain' => env('LOOM_DOMAIN'),

        // Applied before the built-in `viewLoom` gate check, which always runs.
        'middleware' => ['web'],

        // Optional UI-only override of the top-level `index_path`.
        'index_path' => null,

        // Default chain depth on the chain page (clamped to 1-5).
        'chain_depth' => 3,
    ],
];
