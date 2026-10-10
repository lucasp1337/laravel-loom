<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Visitors;

use Lucasp\Loom\Dto\MailableClassRecord;
use Lucasp\Loom\Support\QueueConfig;
use PhpParser\Node;

/**
 * Collects concrete mailable classes with their queue-config properties.
 *
 * @internal
 */
final class MailableClassVisitor extends CollectingVisitor
{
    /** @var list<MailableClassRecord> */
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
