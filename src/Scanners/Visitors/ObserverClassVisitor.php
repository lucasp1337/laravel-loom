<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Visitors;

use Lucasp\Loom\Dto\ClassRecord;
use PhpParser\Node;

/**
 * Records the named classes in a file. Which Eloquent hooks an observer
 * implements is decided from the resolved class (inherited and trait methods
 * included), not from the file.
 *
 * @internal
 */
final class ObserverClassVisitor extends CollectingVisitor
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

        $this->classes[] = new ClassRecord(fqcn: $node->namespacedName->toString(), line: $node->getStartLine());

        return null;
    }

    /** @return list<ClassRecord> */
    public function getClasses(): array
    {
        return $this->classes;
    }
}
