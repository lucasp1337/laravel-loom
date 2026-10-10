<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

/**
 * A class/interface/trait declaration captured for ClassHierarchyResolver.
 *
 * @internal
 */
final class ClassDeclaration
{
    /**
     * @param  'class'|'interface'|'trait'  $kind
     * @param  list<string>  $parents
     * @param  list<string>  $interfaces
     * @param  list<string>  $traits
     * @param  list<MethodDeclaration>  $methods
     * @param  list<TraitAdaptation>  $adaptations
     */
    public function __construct(
        public readonly string $fqcn,
        public readonly string $kind,
        public readonly ?string $parent,
        public readonly array $parents,
        public readonly array $interfaces,
        public readonly array $traits,
        public readonly int $line,
        public readonly bool $isAbstract = false,
        public readonly array $methods = [],
        public readonly array $adaptations = [],
    ) {
    }
}
