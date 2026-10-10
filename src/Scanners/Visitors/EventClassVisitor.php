<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Visitors;

use Lucasp\Loom\Dto\ClassRecord;
use PhpParser\Node;

/**
 * Collects top-level class declarations (FQCN + line).
 *
 * @internal
 */
final class EventClassVisitor extends CollectingVisitor
{
    /** @var list<ClassRecord> */
    private array $classes = [];

    protected function reset(): void
    {
        $this->classes = [];
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
