<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

/**
 * One rule from a `use T { ... }` block. An alias (`T::m as protected n`) has
 * `alias` and/or `visibility`; a precedence rule (`T::m insteadof U`) lists the
 * excluded traits in `insteadof` and is the only kind that sets it.
 *
 * @internal
 */
final readonly class TraitAdaptation
{
    /**
     * @param  list<string>  $insteadof
     */
    public function __construct(
        public ?string $trait,
        public string $method,
        public ?string $alias,
        public ?MethodVisibility $visibility,
        public array $insteadof = [],
    ) {}
}
