<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Visitors;

use Lucasp\Loom\Dto\ClassDeclaration;
use Lucasp\Loom\Dto\MethodDeclaration;
use Lucasp\Loom\Dto\MethodVisibility;
use Lucasp\Loom\Dto\TraitAdaptation;
use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * Collects class/interface/trait declarations for ClassHierarchyResolver.
 * Skips anonymous classes (no namespacedName).
 *
 * @internal
 */
final class ClassDeclarationVisitor extends NodeVisitorAbstract
{
    /** @var list<ClassDeclaration> */
    private array $declarations = [];

    /**
     * @param  array<int, Node>  $nodes
     */
    public function beforeTraverse(array $nodes): ?array
    {
        $this->declarations = [];

        return null;
    }

    public function leaveNode(Node $node): null
    {
        if ($node instanceof Node\Stmt\Class_) {
            $this->captureClass($node);

            return null;
        }

        if ($node instanceof Node\Stmt\Interface_) {
            $this->captureInterface($node);

            return null;
        }

        if ($node instanceof Node\Stmt\Trait_) {
            $this->captureTrait($node);

            return null;
        }

        return null;
    }

    private function captureClass(Node\Stmt\Class_ $node): void
    {
        if (! isset($node->namespacedName)) {
            return;
        }

        $parent = null;
        if ($node->extends instanceof Node\Name) {
            $parent = $node->extends->toString();
        }

        $interfaces = [];
        foreach ($node->implements as $implements) {
            $interfaces[] = $implements->toString();
        }

        $this->declarations[] = new ClassDeclaration(
            fqcn: $node->namespacedName->toString(),
            kind: 'class',
            parent: $parent,
            parents: [],
            interfaces: $interfaces,
            traits: $this->collectTraits($node->stmts),
            line: $node->getStartLine(),
            isAbstract: $node->isAbstract(),
            methods: $this->collectMethods($node->stmts),
            adaptations: $this->collectAdaptations($node->stmts),
        );
    }

    private function captureInterface(Node\Stmt\Interface_ $node): void
    {
        if (! isset($node->namespacedName)) {
            return;
        }

        $parents = [];
        foreach ($node->extends as $extends) {
            $parents[] = $extends->toString();
        }

        $this->declarations[] = new ClassDeclaration(
            fqcn: $node->namespacedName->toString(),
            kind: 'interface',
            parent: null,
            parents: $parents,
            interfaces: [],
            traits: [],
            line: $node->getStartLine(),
        );
    }

    private function captureTrait(Node\Stmt\Trait_ $node): void
    {
        if (! isset($node->namespacedName)) {
            return;
        }

        $this->declarations[] = new ClassDeclaration(
            fqcn: $node->namespacedName->toString(),
            kind: 'trait',
            parent: null,
            parents: [],
            interfaces: [],
            traits: $this->collectTraits($node->stmts),
            line: $node->getStartLine(),
            methods: $this->collectMethods($node->stmts),
            adaptations: $this->collectAdaptations($node->stmts),
        );
    }

    /**
     * @param  array<int, Node\Stmt>  $stmts
     * @return list<string>
     */
    private function collectTraits(array $stmts): array
    {
        $traits = [];
        foreach ($stmts as $stmt) {
            if (! $stmt instanceof Node\Stmt\TraitUse) {
                continue;
            }
            foreach ($stmt->traits as $trait) {
                $traits[] = $trait->toString();
            }
        }

        return $traits;
    }

    /**
     * @param  array<int, Node\Stmt>  $stmts
     * @return list<MethodDeclaration>
     */
    private function collectMethods(array $stmts): array
    {
        $methods = [];
        foreach ($stmts as $stmt) {
            if (! $stmt instanceof Node\Stmt\ClassMethod) {
                continue;
            }

            $first = $stmt->params[0] ?? null;
            $methods[] = new MethodDeclaration(
                name: $stmt->name->toString(),
                visibility: $this->visibility($stmt->flags),
                isAbstract: $stmt->isAbstract(),
                hasParameters: $first !== null,
                firstParameterClasses: $first !== null ? $this->typeClasses($first->type) : [],
            );
        }

        return $methods;
    }

    /**
     * Class names a parameter type accepts: a named type, its nullable form, or
     * the class members of a union. Intersection and builtin types yield none.
     *
     * @return list<string>
     */
    private function typeClasses(Node\ComplexType|Node\Identifier|Node\Name|null $type): array
    {
        if ($type instanceof Node\NullableType) {
            $type = $type->type;
        }

        if ($type instanceof Node\Name) {
            return [$type->toString()];
        }

        if (! $type instanceof Node\UnionType) {
            return [];
        }

        $classes = [];
        foreach ($type->types as $member) {
            if ($member instanceof Node\Name) {
                $classes[] = $member->toString();
            }
        }

        return $classes;
    }

    /**
     * @param  array<int, Node\Stmt>  $stmts
     * @return list<TraitAdaptation>
     */
    private function collectAdaptations(array $stmts): array
    {
        $adaptations = [];
        foreach ($stmts as $stmt) {
            if (! $stmt instanceof Node\Stmt\TraitUse) {
                continue;
            }

            foreach ($stmt->adaptations as $adaptation) {
                if ($adaptation instanceof Node\Stmt\TraitUseAdaptation\Precedence) {
                    $adaptations[] = new TraitAdaptation(
                        trait: $adaptation->trait?->toString(),
                        method: $adaptation->method->toString(),
                        alias: null,
                        visibility: null,
                        insteadof: array_values(array_map(static fn (Node\Name $name): string => $name->toString(), $adaptation->insteadof)),
                    );

                    continue;
                }

                if (! $adaptation instanceof Node\Stmt\TraitUseAdaptation\Alias) {
                    continue;
                }

                $adaptations[] = new TraitAdaptation(
                    trait: $adaptation->trait?->toString(),
                    method: $adaptation->method->toString(),
                    alias: $adaptation->newName?->toString(),
                    visibility: $adaptation->newModifier === null ? null : $this->visibility($adaptation->newModifier),
                );
            }
        }

        return $adaptations;
    }

    private function visibility(int $flags): MethodVisibility
    {
        return match (true) {
            ($flags & Modifiers::PRIVATE) !== 0 => MethodVisibility::PRIVATE,
            ($flags & Modifiers::PROTECTED) !== 0 => MethodVisibility::PROTECTED,
            default => MethodVisibility::PUBLIC,
        };
    }

    /** @return list<ClassDeclaration> */
    public function getDeclarations(): array
    {
        return $this->declarations;
    }
}
