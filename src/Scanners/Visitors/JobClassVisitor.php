<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Visitors;

use Lucasp\Loom\Dto\JobClassRecord;
use Lucasp\Loom\Support\QueueConfig;
use PhpParser\Node;

/**
 * Collects concrete job classes (skips abstract + anonymous).
 *
 * @internal
 */
final class JobClassVisitor extends CollectingVisitor
{
    /** @var list<JobClassRecord> */
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
        if ($node->isAbstract()) {
            return null;
        }

        $this->classes[] = new JobClassRecord(
            fqcn: $node->namespacedName->toString(),
            line: $node->getStartLine(),
            queueConfig: QueueConfig::extractFrom($node),
        );

        return null;
    }

    /** @return list<JobClassRecord> */
    public function getClasses(): array
    {
        return $this->classes;
    }
}
