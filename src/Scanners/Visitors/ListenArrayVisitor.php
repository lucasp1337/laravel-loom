<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Visitors;

use Lucasp\Loom\Support\AstHelpers;
use Lucasp\Loom\Support\IdentifiesEventServiceProvider;
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
    use IdentifiesEventServiceProvider;

    /** @var array<int, array{event: string, listener: string, method: string}> */
    private array $pairs = [];

    /** @var array<int, array{event: string, line: int, registration: string}> */
    private array $closurePairs = [];

    /**
     * @param  array<int, Node>  $nodes
     */
    public function beforeTraverse(array $nodes): ?array
    {
        $this->pairs = [];
        $this->closurePairs = [];
        $this->resetEventServiceProviderStack();

        return null;
    }

    public function enterNode(Node $node): null
    {
        $this->pushClassNode($node);

        return null;
    }

    public function leaveNode(Node $node): null
    {
        // Handle the property on leaveNode so NameResolver has rewritten
        // every ClassConstFetch->class Name inside the default array
        // literal.
        if ($node instanceof Node\Stmt\Property) {
            $this->handleProperty($node);

            return null;
        }

        $this->popClassNode($node);

        return null;
    }

    private function handleProperty(Node\Stmt\Property $node): void
    {
        if (! $this->inEventServiceProvider()) {
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

            // Class-keyed entries flow into both the regular pair slot
            // AND the closure-pair slot. String-keyed entries (e.g.
            // 'eloquent.*' => [Listener::class]) belong to ObserverScanner
            // and must NOT leak into listeners[]; only their closure
            // values are captured.
            $keyIsClass = $item->key instanceof Node\Expr\ClassConstFetch;
            $value = $item->value;

            // Array-of-listeners: route each element through the same emit
            // helper. Single listener: emit the value directly.
            if ($value instanceof Node\Expr\Array_) {
                foreach ($value->items as $listenerItem) {
                    $this->emitListenerEntry($eventFqcn, $keyIsClass, $listenerItem->value);
                }

                continue;
            }

            $this->emitListenerEntry($eventFqcn, $keyIsClass, $value);
        }
    }

    /**
     * Emit one entry from a single listener-position expression. Routes
     * closures to closurePairs[] and class-keyed class refs to pairs[];
     * string-keyed entries skip the regular pair slot.
     */
    private function emitListenerEntry(string $eventFqcn, bool $keyIsClass, Node\Expr $value): void
    {
        if ($value instanceof Node\Expr\Closure || $value instanceof Node\Expr\ArrowFunction) {
            $this->closurePairs[] = [
                'event' => $eventFqcn,
                'line' => $value->getStartLine(),
                'registration' => 'listen_array',
            ];

            return;
        }

        if (! $keyIsClass) {
            return;
        }

        $resolved = $this->listenerFromValue($value);
        if ($resolved === null) {
            return;
        }

        $this->pairs[] = [
            'event' => $eventFqcn,
            'listener' => $resolved['listener'],
            'method' => $resolved['method'],
        ];
    }

    private function eventFromKey(Node\Expr $expr): ?string
    {
        $direct = AstHelpers::classConstFqcn($expr);
        if ($direct !== null) {
            return $direct;
        }

        return $expr instanceof Node\Scalar\String_ ? $expr->value : null;
    }

    /**
     * @return array{listener: string, method: string}|null
     */
    private function listenerFromValue(Node\Expr $value): ?array
    {
        // Bare ::class form.
        $direct = AstHelpers::classConstFqcn($value);
        if ($direct !== null) {
            return ['listener' => $direct, 'method' => 'handle'];
        }

        if (! $value instanceof Node\Expr\Array_ || $value->items === []) {
            return null;
        }

        // Tuple form: [ListenerClass::class, 'method'].
        $tuple = AstHelpers::tupleCallable($value);
        if ($tuple !== null) {
            return ['listener' => $tuple['class'], 'method' => $tuple['method']];
        }

        // Bare-tuple case with a single class element behaves like a direct ::class.
        $first = AstHelpers::classConstFqcn($value->items[0]->value);
        if ($first !== null) {
            return ['listener' => $first, 'method' => 'handle'];
        }

        return null;
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
