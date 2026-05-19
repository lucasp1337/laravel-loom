<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support;

use PhpParser\Node;

/**
 * Track whether the visitor is currently inside an EventServiceProvider
 * class — either named `EventServiceProvider` or extending the Illuminate
 * base. Used by `$listen` / `$subscribe` array visitors.
 */
trait IdentifiesEventServiceProvider
{
    private const EVENT_SERVICE_PROVIDER_BASE = 'Illuminate\\Foundation\\Support\\Providers\\EventServiceProvider';

    /** @var array<int, bool> */
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

        return $node->extends instanceof Node\Name
            && $node->extends->toString() === self::EVENT_SERVICE_PROVIDER_BASE;
    }
}
