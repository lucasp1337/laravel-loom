<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Visitors;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * Collects every class, interface, and trait declaration in a parsed file.
 *
 * Used exclusively by {@see \Lucasp\Loom\Support\ClassHierarchyResolver} to
 * build a cross-file declaration index. Skips anonymous classes (which lack
 * a `namespacedName`).
 *
 * Reads on `leaveNode` so NameResolver has fully resolved every extends /
 * implements / use-trait name before we capture it. All FQCNs are returned
 * without a leading backslash.
 *
 * The visitor is stateful and reset per-file via `beforeTraverse`. The
 * enclosing file path is **not** known to the visitor; callers attach it
 * after traversal (mirrors `JobClassVisitor`).
 *
 * See `docs/design/class-hierarchy-resolver.md` for the design.
 */
final class ClassDeclarationVisitor extends NodeVisitorAbstract
{
    /**
     * @var array<int, array{
     *     fqcn: string,
     *     kind: 'class'|'interface'|'trait',
     *     parent: ?string,
     *     parents: list<string>,
     *     interfaces: list<string>,
     *     traits: list<string>,
     *     line: int,
     * }>
     */
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
        // Anonymous classes have no namespacedName — skip.
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

        $this->declarations[] = [
            'fqcn' => $node->namespacedName->toString(),
            'kind' => 'class',
            'parent' => $parent,
            'parents' => [],
            'interfaces' => $interfaces,
            'traits' => $this->collectTraits($node->stmts),
            'line' => $node->getStartLine(),
        ];
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

        $this->declarations[] = [
            'fqcn' => $node->namespacedName->toString(),
            'kind' => 'interface',
            'parent' => null,
            'parents' => $parents,
            'interfaces' => [],
            'traits' => [],
            'line' => $node->getStartLine(),
        ];
    }

    private function captureTrait(Node\Stmt\Trait_ $node): void
    {
        if (! isset($node->namespacedName)) {
            return;
        }

        $this->declarations[] = [
            'fqcn' => $node->namespacedName->toString(),
            'kind' => 'trait',
            'parent' => null,
            'parents' => [],
            'interfaces' => [],
            'traits' => $this->collectTraits($node->stmts),
            'line' => $node->getStartLine(),
        ];
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
     * @return array<int, array{
     *     fqcn: string,
     *     kind: 'class'|'interface'|'trait',
     *     parent: ?string,
     *     parents: list<string>,
     *     interfaces: list<string>,
     *     traits: list<string>,
     *     line: int,
     * }>
     */
    public function getDeclarations(): array
    {
        return $this->declarations;
    }
}
