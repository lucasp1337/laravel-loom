<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support;

use PhpParser\Node;

/**
 * Shared between visitors that scan `$listen` / `$subscribe` arrays on
 * EventServiceProvider classes. Encapsulates the EventServiceProvider
 * recognition rule (class name is `EventServiceProvider` OR it extends
 * the Illuminate base) and the bool-stack that tracks "are we currently
 * inside one" during traversal.
 *
 * Usage:
 *
 *   - In `beforeTraverse`: $this->resetEventServiceProviderStack();
 *   - In `enterNode`: $this->pushClassNode($node);
 *   - In `leaveNode`: $this->popClassNode($node);
 *   - Inside property handlers: $this->inEventServiceProvider().
 */
trait IdentifiesEventServiceProvider
{
    private const EVENT_SERVICE_PROVIDER_BASE = 'Illuminate\\Foundation\\Support\\Providers\\EventServiceProvider';

    /**
     * Depth-N enclosing-class stack — true when the current class is an
     * EventServiceProvider, false otherwise. PHP allows nested class
     * declarations in conditional blocks; the stack lets the visitor
     * descend into them without forgetting the outer context.
     *
     * @var array<int, bool>
     */
    private array $eventServiceProviderStack = [];

    private function resetEventServiceProviderStack(): void
    {
        $this->eventServiceProviderStack = [];
    }

    private function pushClassNode(Node $node): void
    {
        if ($node instanceof Node\Stmt\Class_) {
            $this->eventServiceProviderStack[] = $this->isEventServiceProvider($node);
        }
    }

    private function popClassNode(Node $node): void
    {
        if ($node instanceof Node\Stmt\Class_) {
            array_pop($this->eventServiceProviderStack);
        }
    }

    private function inEventServiceProvider(): bool
    {
        return $this->eventServiceProviderStack !== []
            && end($this->eventServiceProviderStack) === true;
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
}
