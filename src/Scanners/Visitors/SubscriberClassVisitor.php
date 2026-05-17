<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Visitors;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * Parses subscriber classes — those that expose a `subscribe(Dispatcher $events)`
 * method — and extracts the events they subscribe to.
 *
 * v0.2 supports only the return-array form:
 *
 *     public function subscribe($events): array
 *     {
 *         return [
 *             OrderShipped::class => 'handleOrderShipped',
 *             OrderPaid::class => [self::class, 'handleOrderPaid'],
 *         ];
 *     }
 *
 * The imperative form (`$events->listen(...)`) is a documented gap.
 */
final class SubscriberClassVisitor extends NodeVisitorAbstract
{
    private const SHOULD_QUEUE = 'Illuminate\\Contracts\\Queue\\ShouldQueue';

    /** @var array<int, array{fqcn: string, line: int, queued: bool, handles: array<int, string>}> */
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

        $subscribeMethod = null;
        foreach ($node->stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\ClassMethod && $stmt->name->toString() === 'subscribe') {
                $subscribeMethod = $stmt;
                break;
            }
        }

        if ($subscribeMethod === null) {
            return null;
        }

        $queued = false;
        foreach ($node->implements as $implements) {
            if ($implements->toString() === self::SHOULD_QUEUE) {
                $queued = true;
                break;
            }
        }

        $handles = $this->extractHandles($subscribeMethod);

        $this->classes[] = [
            'fqcn' => $node->namespacedName->toString(),
            'line' => $node->getStartLine(),
            'queued' => $queued,
            'handles' => $handles,
        ];

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function extractHandles(Node\Stmt\ClassMethod $method): array
    {
        if ($method->stmts === null) {
            return [];
        }

        $handles = [];

        foreach ($method->stmts as $stmt) {
            if (! $stmt instanceof Node\Stmt\Return_) {
                continue;
            }
            if (! $stmt->expr instanceof Node\Expr\Array_) {
                continue;
            }

            foreach ($stmt->expr->items as $item) {
                if ($item->key === null) {
                    continue;
                }
                $event = $this->classConstFqcn($item->key);
                if ($event !== null) {
                    $handles[] = $event;
                }
            }

            break;
        }

        return $handles;
    }

    private function classConstFqcn(Node\Expr $expr): ?string
    {
        if (! $expr instanceof Node\Expr\ClassConstFetch) {
            return null;
        }
        if (! $expr->class instanceof Node\Name) {
            return null;
        }
        if (! $expr->name instanceof Node\Identifier) {
            return null;
        }
        if ($expr->name->toString() !== 'class') {
            return null;
        }

        return $expr->class->toString();
    }

    /**
     * @return array<int, array{fqcn: string, line: int, queued: bool, handles: array<int, string>}>
     */
    public function getClasses(): array
    {
        return $this->classes;
    }
}
