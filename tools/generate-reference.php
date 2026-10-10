<?php

declare(strict_types=1);
use Lucasp\Loom\Tools\ReferencePages;

/**
 * Regenerates the generated regions of the reference pages.
 * Usage: php tools/generate-reference.php
 */

require __DIR__.'/../vendor/autoload.php';

$changed = (new ReferencePages(dirname(__DIR__)))->write();

fwrite(STDOUT, $changed === [] ? "Reference pages are up to date.\n" : "Updated:\n  ".implode("\n  ", $changed)."\n");
