<?php

declare(strict_types=1);

/**
 * Docs state what Loom records and misses, not how a given framework version
 * behaves. Version-specific claims ("Laravel 12+") rot, so they fail here.
 * Only the supported-versions statement is allowed.
 */
it('keeps version-specific framework claims out of the docs', function (): void {
    $allowed = ['getting-started.md'];
    $root = dirname(__DIR__, 2).'/docs';
    $found = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'md') {
            continue;
        }
        $relative = substr($file->getPathname(), strlen($root) + 1);
        if (in_array($relative, $allowed, true)) {
            continue;
        }
        if (preg_match('/Laravel\s*\d+\s*\+|Laravel\s+\d+(\.\d+)?\s+(and|or)\s+(later|newer|above)/i', (string) file_get_contents($file->getPathname()), $m) === 1) {
            $found[] = "{$relative}: {$m[0]}";
        }
    }

    expect($found)->toBe([]);
});
