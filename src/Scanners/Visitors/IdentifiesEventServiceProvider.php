<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Visitors;

use Lucasp\Loom\Support\LaravelClasses;
use PhpParser\Node;

/**
 * Whether the visitor is currently inside an EventServiceProvider class,
 * either named `EventServiceProvider` or extending the Illuminate base. Used
 * by `$listen` / `$subscribe` array visitors; builds on class-scope tracking.
 *
 * @internal
 */
trait IdentifiesEventServiceProvider
{
    use TracksClassScope;

    private function inEventServiceProvider(): bool
    {
        $class = $this->currentClassNode();

        return $class instanceof Node\Stmt\Class_ && $this->isEventServiceProvider($class);
    }

    private function isEventServiceProvider(Node\Stmt\Class_ $node): bool
    {
        if ($node->name !== null && $node->name->toString() === 'EventServiceProvider') {
            return true;
        }

        return $node->extends instanceof Node\Name
            && $node->extends->toString() === LaravelClasses::EVENT_SERVICE_PROVIDER->value;
    }
}
