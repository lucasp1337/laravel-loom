<?php

declare(strict_types=1);

namespace Lucasp\Loom\Tools\Regions;

use Stringable;

/** The name in a `<!-- generated:NAME -->` marker. */
final readonly class RegionName implements Stringable
{
    private function __construct(public string $value) {}

    public static function fixed(Region $region): self
    {
        return new self($region->value);
    }

    public static function keyed(RegionPrefix $prefix, string $key): self
    {
        return new self($prefix->value.':'.$key);
    }

    /** A name read from a page, which may or may not be one a source produces. */
    public static function fromString(string $value): self
    {
        return new self($value);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
