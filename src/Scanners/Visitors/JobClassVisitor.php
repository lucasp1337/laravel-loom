<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Visitors;

use Lucasp\Loom\Dto\JobClassRecord;
use Lucasp\Loom\Support\AstHelpers;
use Lucasp\Loom\Support\LaravelClasses;
use Lucasp\Loom\Support\QueueConfig;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/** Collects concrete job classes (skips abstract + anonymous). */
final class JobClassVisitor extends NodeVisitorAbstract
{
    /** @var list<JobClassRecord> */
    private array $classes = [];

    /**
     * @param  array<int, Node>  $nodes
     */
    public function beforeTraverse(array $nodes): ?array
    {
        $this->classes = [];

        return null;
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
            queued: AstHelpers::declaresInterface($node, LaravelClasses::SHOULD_QUEUE->value),
            hasHandle: $this->declaresHandleMethod($node),
            queueConfig: QueueConfig::extractFrom($node),
        );

        return null;
    }

    private function declaresHandleMethod(Node\Stmt\Class_ $node): bool
    {
        foreach ($node->stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\ClassMethod && $stmt->name->toString() === 'handle') {
                return true;
            }
        }

        return false;
    }

    /** @return list<JobClassRecord> */
    public function getClasses(): array
    {
        return $this->classes;
    }
}
