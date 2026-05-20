<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Visitors;

use Lucasp\Loom\Dto\ListenerClassRecord;
use Lucasp\Loom\Dto\ListenerHandle;
use Lucasp\Loom\Support\AstHelpers;
use Lucasp\Loom\Support\LaravelClasses;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * Collects listener classes and their handle() event-type signature.
 */
final class ListenerClassVisitor extends NodeVisitorAbstract
{
    /** @var list<ListenerClassRecord> */
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

        $queued = AstHelpers::declaresInterface($node, LaravelClasses::SHOULD_QUEUE->value);

        $hasHandle = false;
        $handles = [];

        foreach ($node->stmts as $stmt) {
            if (! $stmt instanceof Node\Stmt\ClassMethod) {
                continue;
            }
            if ($stmt->name->toString() !== 'handle') {
                continue;
            }

            $hasHandle = true;
            if ($stmt->params !== []) {
                $type = $stmt->params[0]->type;
                // Only bare Node\Name supported — unions/intersections/nullables are gaps.
                if ($type instanceof Node\Name) {
                    $handles[] = new ListenerHandle(event: $type->toString(), method: 'handle');
                }
            }
            break;
        }

        $this->classes[] = new ListenerClassRecord(
            fqcn: $node->namespacedName->toString(),
            line: $node->getStartLine(),
            queued: $queued,
            hasHandle: $hasHandle,
            handles: $handles,
        );

        return null;
    }

    /** @return list<ListenerClassRecord> */
    public function getClasses(): array
    {
        return $this->classes;
    }
}
