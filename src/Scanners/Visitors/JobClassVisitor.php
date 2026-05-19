<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Visitors;

use Lucasp\Loom\Support\AstHelpers;
use Lucasp\Loom\Support\LaravelClasses;
use Lucasp\Loom\Support\QueueConfig;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * Collects concrete job classes from a parsed file, capturing the
 * queue-config properties declared directly on the class.
 *
 * Skips:
 *  - Abstract classes
 *  - Anonymous classes (no namespacedName)
 *  - Interfaces and traits (different node types — never match Stmt\Class_)
 *
 * `queued` is true iff `ShouldQueue` appears in the DIRECT `implements`
 * list (post-NameResolver). Indirect inheritance is computed by
 * `JobsScanner` via the resolver, not here.
 *
 * Reads on `leaveNode` so NameResolver has fully resolved every name
 * inside the class body before we inspect it.
 */
final class JobClassVisitor extends NodeVisitorAbstract
{
    /**
     * @var array<int, array{
     *     fqcn: string,
     *     line: int,
     *     queued: bool,
     *     has_handle: bool,
     *     queue_config: array<string, string|int|null>,
     * }>
     */
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

        $this->classes[] = [
            'fqcn' => $node->namespacedName->toString(),
            'line' => $node->getStartLine(),
            'queued' => AstHelpers::declaresInterface($node, LaravelClasses::SHOULD_QUEUE->value),
            'has_handle' => $this->declaresHandleMethod($node),
            'queue_config' => QueueConfig::extractFrom($node),
        ];

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

    /**
     * @return array<int, array{
     *     fqcn: string,
     *     line: int,
     *     queued: bool,
     *     has_handle: bool,
     *     queue_config: array<string, string|int|null>,
     * }>
     */
    public function getClasses(): array
    {
        return $this->classes;
    }
}
