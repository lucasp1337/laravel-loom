<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Visitors;

use Lucasp\Loom\Dto\ListenerClassRecord;
use Lucasp\Loom\Support\AstHelpers;
use Lucasp\Loom\Support\LaravelClasses;
use PhpParser\Node;

/**
 * Collects the named classes in a file. Which methods handle which events is
 * decided from the resolved class (inherited and trait methods included), not
 * from the file.
 *
 * @internal
 */
final class ListenerClassVisitor extends CollectingVisitor
{
    /** @var list<ListenerClassRecord> */
    private array $classes = [];

    protected function reset(): void
    {
        $this->classes = [];
    }

    public function leaveNode(Node $node): null
    {
        if (! $node instanceof Node\Stmt\Class_) {
            return null;
        }

        if ($node->namespacedName === null) {
            return null;
        }

        $this->classes[] = new ListenerClassRecord(
            fqcn: $node->namespacedName->toString(),
            line: $node->getStartLine(),
            queued: AstHelpers::declaresInterface($node, LaravelClasses::SHOULD_QUEUE->value),
        );

        return null;
    }

    /** @return list<ListenerClassRecord> */
    public function getClasses(): array
    {
        return $this->classes;
    }
}
