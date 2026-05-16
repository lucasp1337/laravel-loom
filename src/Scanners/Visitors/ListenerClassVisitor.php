<?php

declare(strict_types=1);

namespace Lucasp\Atlas\Scanners\Visitors;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * Collects listener classes and the shape of their handle() method.
 *
 * Skips anonymous classes (no namespacedName). Abstract classes are kept —
 * concrete subclasses may register through them and consumers can traverse
 * inheritance themselves.
 */
final class ListenerClassVisitor extends NodeVisitorAbstract
{
    private const SHOULD_QUEUE = 'Illuminate\\Contracts\\Queue\\ShouldQueue';

    /** @var array<int, array{fqcn: string, line: int, queued: bool, has_handle: bool, handles: array<int, string>}> */
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

        $queued = false;
        foreach ($node->implements as $implements) {
            if ($implements->toString() === self::SHOULD_QUEUE) {
                $queued = true;
                break;
            }
        }

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
                    $handles[] = $type->toString();
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
     * @return array<int, array{fqcn: string, line: int, queued: bool, has_handle: bool, handles: array<int, string>}>
     */
    public function getClasses(): array
    {
        return $this->classes;
    }
}
