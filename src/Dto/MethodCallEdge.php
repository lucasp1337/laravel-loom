<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

/**
 * A call from one method of a class to another method of the same class
 * (`$this->helper()`, `self::helper()`, `static::helper()`). Internal to the
 * cross-link pass, which uses it to attribute a dispatch made in a helper to
 * the handler methods that reach it.
 *
 * @internal
 */
final readonly class MethodCallEdge
{
    public function __construct(
        public string $classFqcn,
        public string $caller,
        public string $callee,
    ) {}
}
