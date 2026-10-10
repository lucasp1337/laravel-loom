<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

use Lucasp\Loom\Support\RouteFileLoader;
use PhpParser\Node\Expr;

/**
 * One path expression handed to a route-file loading call.
 *
 * @internal
 */
final readonly class RouteFileReference
{
    public function __construct(
        public RouteFileLoader $loader,
        public Expr $path,
        public int $line,
        /** True when a directory is a legitimate value (command paths), so a non-file is not an error. */
        public bool $allowsDirectory = false,
    ) {
    }
}
