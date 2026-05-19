<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Visitors;

use Lucasp\Loom\Support\AstHelpers;
use Lucasp\Loom\Support\LaravelClasses;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * Collects listener classes and their handle() event-type signature.
 */
final class ListenerClassVisitor extends NodeVisitorAbstract
{
    /** @var array<int, array{fqcn: string, line: int, queued: bool, has_handle: bool, handles: array<int, array{event: string, method: string}>}> */
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
        // Read on leaveNode so NameResolver has already rewritten every Node\Name
        // inside the class body (including parameter type-hints) to its FQCN.
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
                // Only a bare Node\Name counts as a usable event type — unions,
                // intersections, nullables, and builtins are documented gaps.
                if ($type instanceof Node\Name) {
                    $handles[] = ['event' => $type->toString(), 'method' => 'handle'];
                }
            }
            break;
        }

        $this->classes[] = [
            'fqcn' => $node->namespacedName->toString(),
            'line' => $node->getStartLine(),
            'queued' => $queued,
            'has_handle' => $hasHandle,
            'handles' => $handles,
        ];

        return null;
    }

    /**
     * @return array<int, array{fqcn: string, line: int, queued: bool, has_handle: bool, handles: array<int, array{event: string, method: string}>}>
     */
    public function getClasses(): array
    {
        return $this->classes;
    }
}
