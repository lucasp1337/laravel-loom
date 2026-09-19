<?php

declare(strict_types=1);

return [
    'ui' => [
        // Mount the read-only browser UI.
        'enabled' => env('LOOM_UI_ENABLED', true),

        // URI prefix and optional domain the UI is served under.
        'path' => env('LOOM_PATH', 'loom'),
        'domain' => env('LOOM_DOMAIN'),

        // Applied before the built-in `viewLoom` gate check, which always runs.
        'middleware' => ['web'],

        // Snapshot the UI reads; null means storage/loom/index.json.
        'index_path' => env('LOOM_INDEX_PATH'),

        // Default chain depth on the chain page (clamped to 1-6).
        'chain_depth' => 3,
    ],
];
