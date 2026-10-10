<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

/**
 * A class/interface/trait declaration captured for ClassHierarchyResolver.
 *
 * @internal
 */
final readonly class ClassDeclaration
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
        public string $fqcn,
        public string $kind,
        public ?string $parent,
        public array $parents,
        public array $interfaces,
        public array $traits,
        public int $line,
        public bool $isAbstract = false,
        public array $methods = [],
        public array $adaptations = [],
    ) {}
}
