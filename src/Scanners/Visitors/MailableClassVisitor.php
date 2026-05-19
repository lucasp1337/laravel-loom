<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Visitors;

use Lucasp\Loom\Support\QueueConfig;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * Collects concrete mailable classes from a parsed file, capturing the
 * queue-config properties declared directly on the class.
 *
 * Skips:
 *  - Abstract classes
 *  - Anonymous classes (no namespacedName)
 *  - Interfaces and traits (different node types — never match Stmt\Class_)
 *
 * `queued` is NOT computed here — the scanner calls the class hierarchy
 * resolver to decide. Mirrors JobClassVisitor's shape, minus the
 * `has_handle` field (mailables don't need it for any downstream decision).
 *
 * Reads on `leaveNode` so NameResolver has fully resolved every name
 * inside the class body before we inspect it.
 */
final class MailableClassVisitor extends NodeVisitorAbstract
{
    /**
     * @var array<int, array{
     *     fqcn: string,
     *     line: int,
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
            'queue_config' => QueueConfig::extractFrom($node),
        ];

        return null;
    }

    /**
     * @return array<int, array{
     *     fqcn: string,
     *     line: int,
     *     queue_config: array<string, string|int|null>,
     * }>
     */
    public function getClasses(): array
    {
        return $this->classes;
    }
}
