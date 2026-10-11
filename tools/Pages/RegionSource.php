<?php

declare(strict_types=1);

namespace Lucasp\Loom\Tools\Pages;

/**
 * Produces generated regions of reference pages. A region is a block between
 * `<!-- generated:NAME -->` markers; each source names the regions it owns.
 */
interface RegionSource
{
    /**
     * @return array<string, array{page: string, body: string}> region name => page path and markdown
     */
    public function regions(): array;
}
