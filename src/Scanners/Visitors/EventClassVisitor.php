<?php

declare(strict_types=1);

namespace Lucasp\Atlas\Scanners\Visitors;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * Collects every top-level class declaration in a parsed file.
 *
 * Anonymous classes are skipped because they have no namespacedName.
 * Interfaces and traits are different node types and never match Stmt\Class_.
 */
final class EventClassVisitor extends NodeVisitorAbstract
{
    /** @var array<int, array{fqcn: string, line: int}> */
    private array $classes = [];

    /**
     * @param  array<int, Node>  $nodes
     */
    public function beforeTraverse(array $nodes): ?array
    {
        $this->classes = [];

        return null;
    }

    public function enterNode(Node $node): null
    {
        if (! $node instanceof Node\Stmt\Class_) {
            return null;
        }

        if ($node->namespacedName === null) {
            return null;
        }

        $this->classes[] = [
            'fqcn' => $node->namespacedName->toString(),
            'line' => $node->getStartLine(),
        ];

        return null;
    }

    /**
     * @return array<int, array{fqcn: string, line: int}>
     */
    public function getClasses(): array
    {
        return $this->classes;
    }
}
