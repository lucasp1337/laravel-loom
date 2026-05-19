<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Visitors;

use Lucasp\Loom\Support\QueueConfig;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * Collects concrete mailable classes with their queue-config properties.
 * `queued` is resolved by the scanner via ClassHierarchyResolver.
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
