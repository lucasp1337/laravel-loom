<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Visitors;

use Lucasp\Loom\Dto\MailableClassRecord;
use Lucasp\Loom\Support\QueueConfig;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/** Collects concrete mailable classes with their queue-config properties. */
final class MailableClassVisitor extends NodeVisitorAbstract
{
    /** @var list<MailableClassRecord> */
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

        $this->classes[] = new MailableClassRecord(
            fqcn: $node->namespacedName->toString(),
            line: $node->getStartLine(),
            queueConfig: QueueConfig::extractFrom($node),
        );

        return null;
    }

    /** @return list<MailableClassRecord> */
    public function getClasses(): array
    {
        return $this->classes;
    }
}
