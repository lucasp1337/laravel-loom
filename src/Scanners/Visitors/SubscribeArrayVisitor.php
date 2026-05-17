<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Visitors;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * Collects subscriber FQCNs from `$subscribe` arrays declared on
 * EventServiceProvider classes.
 *
 * Mirrors ListenArrayVisitor's class-shape filter so domain-specific
 * service providers (extending EventServiceProvider) are also covered.
 */
final class SubscribeArrayVisitor extends NodeVisitorAbstract
{
    private const EVENT_SERVICE_PROVIDER_BASE = 'Illuminate\\Foundation\\Support\\Providers\\EventServiceProvider';

    /** @var array<int, string> */
    private array $subscribers = [];

    /** @var array<int, bool> */
    private array $classStack = [];

    /**
     * @param  array<int, Node>  $nodes
     */
    public function beforeTraverse(array $nodes): ?array
    {
        $this->subscribers = [];
        $this->classStack = [];

        return null;
    }

    public function enterNode(Node $node): null
    {
        if ($node instanceof Node\Stmt\Class_) {
            $this->classStack[] = $this->isEventServiceProvider($node);
        }

        return null;
    }

    public function leaveNode(Node $node): null
    {
        if ($node instanceof Node\Stmt\Property) {
            $this->handleProperty($node);

            return null;
        }

        if ($node instanceof Node\Stmt\Class_) {
            array_pop($this->classStack);
        }

        return null;
    }

    private function isEventServiceProvider(Node\Stmt\Class_ $node): bool
    {
        if ($node->name !== null && $node->name->toString() === 'EventServiceProvider') {
            return true;
        }

        if ($node->extends instanceof Node\Name
            && $node->extends->toString() === self::EVENT_SERVICE_PROVIDER_BASE
        ) {
            return true;
        }

        return false;
    }

    private function handleProperty(Node\Stmt\Property $node): void
    {
        if ($this->classStack === [] || end($this->classStack) !== true) {
            return;
        }

        foreach ($node->props as $prop) {
            if ($prop->name->toString() !== 'subscribe') {
                continue;
            }
            if (! $prop->default instanceof Node\Expr\Array_) {
                continue;
            }

            foreach ($prop->default->items as $item) {
                $fqcn = $this->classConstFqcn($item->value);
                if ($fqcn !== null) {
                    $this->subscribers[] = $fqcn;
                }
            }
        }
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
     * @return array<int, string>
     */
    public function getSubscribers(): array
    {
        return $this->subscribers;
    }
}
