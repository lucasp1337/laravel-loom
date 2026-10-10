<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

use Lucasp\Loom\Support\RouteFileLoader;
use Lucasp\Loom\Support\RouteGroupAttribute;

/**
 * A group attribute at a route-file loading call that Loom could not resolve,
 * so the loaded file's routes may be missing it.
 *
 * @internal
 */
final readonly class UnresolvedGroupAttribute
{
    public function __construct(
        public string $file,
        public int $line,
        public RouteFileLoader $loader,
        public RouteGroupAttribute $attribute,
    ) {}

    public function message(): string
    {
        $subject = $this->attribute === RouteGroupAttribute::ATTRIBUTES
            ? 'group attributes are'
            : 'group '.$this->attribute->value.' is';

        return $this->loader->value.': '.$subject.' not statically resolvable, so routes of the loaded file may miss it';
    }
}
