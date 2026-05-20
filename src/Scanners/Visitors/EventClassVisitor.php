<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Visitors;

use Lucasp\Loom\Dto\ClassRecord;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/** Collects top-level class declarations (FQCN + line). */
final class EventClassVisitor extends NodeVisitorAbstract
{
    /** @var list<ClassRecord> */
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

        $this->classes[] = new ClassRecord(
            fqcn: $node->namespacedName->toString(),
            line: $node->getStartLine(),
        );

        return null;
    }

    /** @return list<ClassRecord> */
    public function getClasses(): array
    {
        return $this->classes;
    }
}
