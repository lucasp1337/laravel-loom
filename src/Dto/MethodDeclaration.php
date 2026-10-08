<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

/** A method declared in a class or trait body, as captured for ClassHierarchyResolver. */
final class MethodDeclaration
{
    /**
     * @param  list<string>  $firstParameterClasses  class names in the first parameter's type; `self`/`parent` stay literal
     */
    public function __construct(
        public readonly string $name,
        public readonly MethodVisibility $visibility,
        public readonly bool $isAbstract,
        public readonly bool $hasParameters,
        public readonly array $firstParameterClasses,
    ) {
    }
}
