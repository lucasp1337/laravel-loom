<?php

declare(strict_types=1);

use Lucasp\Loom\Tools\WordCount;

/**
 * One ceiling for the whole docs tree so it cannot silently regrow. Behaviour
 * belongs in tests and docblocks; raise the number only with a reason.
 *
 * Words are whitespace-separated tokens with a letter or digit (WordCount).
 */
const DOCS_TOTAL_BUDGET = 24000;

it('keeps the docs within the total word budget', function (): void {
    $root = dirname(__DIR__, 2).'/docs';
    $total = 0;
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if ($file instanceof SplFileInfo && $file->getExtension() === 'md') {
            $total += WordCount::of((string) file_get_contents($file->getPathname()));
        }
    }

    expect($total)->toBeLessThanOrEqual(DOCS_TOTAL_BUDGET);
});
