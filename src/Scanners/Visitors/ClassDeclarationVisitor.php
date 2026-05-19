<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Visitors;

use Lucasp\Loom\Dto\ClassDeclaration;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * Collects class/interface/trait declarations for ClassHierarchyResolver.
 * Skips anonymous classes (no namespacedName).
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

    /** @return list<ClassDeclaration> */
    public function getDeclarations(): array
    {
        return $this->declarations;
    }
}
