<?php

declare(strict_types=1);

namespace Lucasp\Loom\Tools\Regions;

/** One block of markdown a source offers, independent of any page. */
final readonly class GeneratedRegion
{
    public RegionName $name;

    public function __construct(Region|RegionName $name, public string $body)
    {
        $this->name = $name instanceof Region ? RegionName::fixed($name) : $name;
    }
}
