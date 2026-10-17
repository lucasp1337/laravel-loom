<?php

declare(strict_types=1);
use Lucasp\Loom\Tools\ReferencePages;

/**
 * Regenerates the generated regions of the docs, or with --check only lists
 * the pages that drifted and exits non-zero without writing anything.
 * Usage: `just docs-generate`, or `just docs-check` for the read-only check.
 */

require __DIR__.'/../vendor/autoload.php';

$pages = new ReferencePages(dirname(__DIR__));

if (in_array('--check', $argv, true)) {
    $drifted = $pages->drifted();
    if ($drifted === []) {
        fwrite(STDOUT, "Generated regions are up to date.\n");
        exit(0);
    }
    fwrite(STDERR, "Generated regions have drifted; run `just docs-generate`:\n  ".implode("\n  ", $drifted)."\n");
    exit(1);
}

$changed = $pages->write();

fwrite(STDOUT, $changed === [] ? "Generated regions are up to date.\n" : "Updated:\n  ".implode("\n  ", $changed)."\n");
