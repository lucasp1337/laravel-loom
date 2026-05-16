<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Visitors;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * Collects (event, listener) pairs from `$listen` arrays declared on
 * EventServiceProvider classes.
 *
 * Skips closure values, dynamic keys, and string-keyed entries (e.g.
 * 'eloquent.*' belongs to ObserverScanner).
 */
final class ListenArrayVisitor extends NodeVisitorAbstract
{
    private const EVENT_SERVICE_PROVIDER_BASE = 'Illuminate\\Foundation\\Support\\Providers\\EventServiceProvider';

    /** @var array<int, array{event: string, listener: string}> */
    private array $pairs = [];

    /**
     * Depth-1 enclosing-class stack. PHP allows nested class declarations in
     * conditional blocks; we only treat the outermost qualifying class as a
     * potential event service provider.
     *
     * @var array<int, bool>
     */
    private array $classStack = [];

    /**
     * @param  array<int, Node>  $nodes
     */
    public function beforeTraverse(array $nodes): ?array
    {
        $this->pairs = [];
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
        // Handle the property on leaveNode so that NameResolver has rewritten
        // every ClassConstFetch->class Name inside the default array literal.
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
            if ($prop->name->toString() !== 'listen') {
                continue;
            }
            if (! $prop->default instanceof Node\Expr\Array_) {
                continue;
            }

            $this->collectPairs($prop->default);
        }
    }

    private function collectPairs(Node\Expr\Array_ $array): void
    {
        foreach ($array->items as $item) {
            if ($item->key === null) {
                continue;
            }

            $eventFqcn = $this->classConstFqcn($item->key);
            if ($eventFqcn === null) {
                continue;
            }

            $value = $item->value;

            if ($value instanceof Node\Expr\Array_) {
                foreach ($value->items as $listenerItem) {
                    $listenerFqcn = $this->listenerFromValue($listenerItem->value);
                    if ($listenerFqcn !== null) {
                        $this->pairs[] = ['event' => $eventFqcn, 'listener' => $listenerFqcn];
                    }
                }

                continue;
            }

            // Single listener written without an enclosing array — uncommon but legal.
            $listenerFqcn = $this->listenerFromValue($value);
            if ($listenerFqcn !== null) {
                $this->pairs[] = ['event' => $eventFqcn, 'listener' => $listenerFqcn];
            }
        }
    }

    private function listenerFromValue(Node\Expr $value): ?string
    {
        $direct = $this->classConstFqcn($value);
        if ($direct !== null) {
            return $direct;
        }

        // Tuple form: [ListenerClass::class, 'method']. v0.1 discards the method.
        if ($value instanceof Node\Expr\Array_ && $value->items !== []) {
            return $this->classConstFqcn($value->items[0]->value);
        }

        return null;
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
     * @return array<int, array{event: string, listener: string}>
     */
    public function getPairs(): array
    {
        return $this->pairs;
    }
}
