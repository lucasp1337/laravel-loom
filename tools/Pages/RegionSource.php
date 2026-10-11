<?php

declare(strict_types=1);

namespace Lucasp\Loom\Tools\Pages;

use Lucasp\Loom\Tools\Regions\GeneratedRegion;
use Lucasp\Loom\Tools\SourceContext;
use Lucasp\Loom\Tools\SourceRegistry;

/**
 * Produces generated regions. A source never names a page: a region appears
 * wherever a page carries its marker pair. Every non-abstract implementation
 * in this directory is discovered by {@see SourceRegistry}
 * and is constructed with a {@see SourceContext}.
 */
interface RegionSource
{
    /** @return list<GeneratedRegion> */
    public function regions(): array;
}
