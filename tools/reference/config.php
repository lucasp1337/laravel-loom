<?php

declare(strict_types=1);

/**
 * Hand-written layer for the config tables: a sentence appended to a key's
 * meaning, usually a link to the section that explains it. The meaning itself
 * comes from the comment above the key in config/loom.php.
 */
return [
    'see' => [
        'scan.paths' => 'See [How directories resolve](#how-directories-resolve).',
        'scan.discover_routes' => 'See [Route files](#route-files).',
        'scan.exclude' => 'See [Exclude globs](#exclude-globs).',
    ],
];
