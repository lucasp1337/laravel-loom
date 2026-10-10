<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Visitors;

use PhpParser\Node;

/**
 * Tracks the enclosing class or trait, and the method being walked, for a
 * {@see CollectingVisitor}. Call {@see self::enterClassScope()} from
 * `enterNode()` and {@see self::leaveClassScope()} from `leaveNode()`; the
 * stack is cleared by the base class's final `beforeTraverse()`.
 *
 * @internal
 */
trait TracksClassScope
{
    /** @var list<array{node: Node\Stmt\Class_|Node\Stmt\Trait_, method: ?string}> */
    private array $classScope = [];

    protected function resetClassScope(): void
    {
        $this->classScope = [];
    }

    private function enterClassScope(Node $node): void
    {
        // Class, anonymous class, or trait: open a new scope frame.
        if ($node instanceof Node\Stmt\Class_ || $node instanceof Node\Stmt\Trait_) {
            $this->classScope[] = ['node' => $node, 'method' => null];

            return;
        }

        // Method declaration: record it on the innermost frame.
        if ($node instanceof Node\Stmt\ClassMethod && $this->classScope !== []) {
            $this->classScope[count($this->classScope) - 1]['method'] = $node->name->toString();
        }
    }

    private function leaveClassScope(Node $node): void
    {
        // Closing a class, anonymous class, or trait: drop its frame.
        if ($node instanceof Node\Stmt\Class_ || $node instanceof Node\Stmt\Trait_) {
            array_pop($this->classScope);

            return;
        }

        // Leaving a method: the innermost frame is no longer inside one.
        if ($node instanceof Node\Stmt\ClassMethod && $this->classScope !== []) {
            $this->classScope[count($this->classScope) - 1]['method'] = null;
        }
    }

    /** The innermost enclosing class or trait node, or null at file level. */
    private function currentClassNode(): Node\Stmt\Class_|Node\Stmt\Trait_|null
    {
        if ($this->classScope === []) {
            return null;
        }

        return $this->classScope[count($this->classScope) - 1]['node'];
    }

    /** FQCN of the enclosing class or trait; null at file level or for an anonymous class. */
    private function currentClassFqcn(): ?string
    {
        return $this->currentClassNode()?->namespacedName?->toString();
    }

    private function currentMethod(): ?string
    {
        if ($this->classScope === []) {
            return null;
        }

        return $this->classScope[count($this->classScope) - 1]['method'];
    }
}
