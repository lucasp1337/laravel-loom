<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

/**
 * One argument of a call that names a class (`X::class` or `new X`), with the
 * position and the name it was passed by.
 *
 * @internal
 */
final readonly class ClassArgument
{
    public function __construct(
        public int $position,
        public ?string $name,
        public string $target,
    ) {}
}
