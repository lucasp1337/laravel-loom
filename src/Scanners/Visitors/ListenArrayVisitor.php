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

    /** @var array<int, array{event: string, listener: string, method: string}> */
    private array $pairs = [];

    /** @var array<int, array{event: string, line: int, registration: string}> */
    private array $closurePairs = [];

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
        $this->closurePairs = [];
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

            $eventFqcn = $this->eventFromKey($item->key);
            if ($eventFqcn === null) {
                continue;
            }

            // Class-keyed entries flow into both the regular pair slot AND the
            // closure-pair slot. String-keyed entries (e.g. 'eloquent.*' =>
            // [Listener::class]) belong to ObserverScanner and must NOT leak
            // into listeners[]; only their closure values are captured.
            $keyIsClass = $item->key instanceof Node\Expr\ClassConstFetch;

            $value = $item->value;

            if ($value instanceof Node\Expr\Array_) {
                foreach ($value->items as $listenerItem) {
                    $listenerValue = $listenerItem->value;
                    if ($listenerValue instanceof Node\Expr\Closure
                        || $listenerValue instanceof Node\Expr\ArrowFunction
                    ) {
                        $this->closurePairs[] = [
                            'event' => $eventFqcn,
                            'line' => $listenerValue->getStartLine(),
                            'registration' => 'listen_array',
                        ];

                        continue;
                    }

                    if (! $keyIsClass) {
                        continue;
                    }

                    $resolved = $this->listenerFromValue($listenerValue);
                    if ($resolved !== null) {
                        $this->pairs[] = [
                            'event' => $eventFqcn,
                            'listener' => $resolved['listener'],
                            'method' => $resolved['method'],
                        ];
                    }
                }

                continue;
            }

            if ($value instanceof Node\Expr\Closure || $value instanceof Node\Expr\ArrowFunction) {
                $this->closurePairs[] = [
                    'event' => $eventFqcn,
                    'line' => $value->getStartLine(),
                    'registration' => 'listen_array',
                ];

                continue;
            }

            if (! $keyIsClass) {
                continue;
            }

            // Single listener written without an enclosing array — uncommon but legal.
            $resolved = $this->listenerFromValue($value);
            if ($resolved !== null) {
                $this->pairs[] = [
                    'event' => $eventFqcn,
                    'listener' => $resolved['listener'],
                    'method' => $resolved['method'],
                ];
            }
        }
    }

    private function eventFromKey(Node\Expr $expr): ?string
    {
        $direct = $this->classConstFqcn($expr);
        if ($direct !== null) {
            return $direct;
        }

        if ($expr instanceof Node\Scalar\String_) {
            return $expr->value;
        }

        return null;
    }

    /**
     * @return array{listener: string, method: string}|null
     */
    private function listenerFromValue(Node\Expr $value): ?array
    {
        $direct = $this->classConstFqcn($value);
        if ($direct !== null) {
            return ['listener' => $direct, 'method' => 'handle'];
        }

        // Tuple form: [ListenerClass::class, 'method'].
        if ($value instanceof Node\Expr\Array_ && count($value->items) >= 2) {
            $listener = $this->classConstFqcn($value->items[0]->value);
            if ($listener === null) {
                return null;
            }
            $methodNode = $value->items[1]->value;
            if (! $methodNode instanceof Node\Scalar\String_) {
                return null;
            }

            return ['listener' => $listener, 'method' => $methodNode->value];
        }

        // Bare-tuple case with a single class element behaves like a direct ::class.
        if ($value instanceof Node\Expr\Array_ && $value->items !== []) {
            $listener = $this->classConstFqcn($value->items[0]->value);
            if ($listener !== null) {
                return ['listener' => $listener, 'method' => 'handle'];
            }
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
     * @return array<int, array{event: string, listener: string, method: string}>
     */
    public function getPairs(): array
    {
        return $this->pairs;
    }

    /**
     * @return array<int, array{event: string, line: int, registration: string}>
     */
    public function getClosurePairs(): array
    {
        return $this->closurePairs;
    }
}
