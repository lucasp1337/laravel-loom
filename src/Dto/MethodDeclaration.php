<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

/**
 * A method declared in a class or trait body, as captured for ClassHierarchyResolver.
 *
 * @internal
 */
final readonly class MethodDeclaration
{
    /**
     * @param  list<string>  $firstParameterClasses  class names in the first parameter's type; `self`/`parent` stay literal
     */
    public function __construct(
        public string $name,
        public MethodVisibility $visibility,
        public bool $isAbstract,
        public bool $hasParameters,
        public array $firstParameterClasses,
    ) {}
}
