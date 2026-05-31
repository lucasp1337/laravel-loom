<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Visitors;

use Lucasp\Loom\Dto\ClosurePairRecord;
use Lucasp\Loom\Dto\ListenerPair;
use Lucasp\Loom\Index\ListenerRegistration;
use Lucasp\Loom\Support\AstHelpers;
use Lucasp\Loom\Support\IdentifiesEventServiceProvider;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * Collects (event, listener) pairs from `$listen` on EventServiceProvider classes.
 * String-keyed entries (e.g. 'eloquent.*') belong to ObserverScanner.
 */
final class ListenArrayVisitor extends NodeVisitorAbstract
{
    use IdentifiesEventServiceProvider;

    /** @var list<ListenerPair> */
    private array $pairs = [];

    /** @var list<ClosurePairRecord> */
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

            // String-keyed entries skip the regular pair slot; only their
            // closure values are captured.
            $keyIsClass = $item->key instanceof Node\Expr\ClassConstFetch;
            $value = $item->value;

            if ($value instanceof Node\Expr\Array_) {
                foreach ($value->items as $listenerItem) {
                    $this->emitListenerEntry($eventFqcn, $keyIsClass, $listenerItem->value);
                }

                continue;
            }

            $this->emitListenerEntry($eventFqcn, $keyIsClass, $value);
        }
    }

    private function emitListenerEntry(string $eventFqcn, bool $keyIsClass, Node\Expr $value): void
    {
        if ($value instanceof Node\Expr\Closure || $value instanceof Node\Expr\ArrowFunction) {
            $this->closurePairs[] = new ClosurePairRecord(
                event: $eventFqcn,
                line: $value->getStartLine(),
                endLine: $value->getEndLine(),
                registration: ListenerRegistration::LISTEN_ARRAY,
            );

            return;
        }

        if (! $keyIsClass) {
            return;
        }

        $resolved = $this->listenerFromValue($value);
        if ($resolved === null) {
            return;
        }

        $this->pairs[] = new ListenerPair(
            event: $eventFqcn,
            listener: $resolved['listener'],
            method: $resolved['method'],
        );
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
        $direct = AstHelpers::classConstFqcn($value);
        if ($direct !== null) {
            return ['listener' => $direct, 'method' => 'handle'];
        }

        if (! $value instanceof Node\Expr\Array_ || $value->items === []) {
            return null;
        }

        // [ListenerClass::class, 'method'] tuple.
        $tuple = AstHelpers::tupleCallable($value);
        if ($tuple !== null) {
            return ['listener' => $tuple['class'], 'method' => $tuple['method']];
        }

        // Single-element array acts like a bare ::class.
        $first = AstHelpers::classConstFqcn($value->items[0]->value);
        if ($first !== null) {
            return ['listener' => $first, 'method' => 'handle'];
        }

        return null;
    }

    /** @return list<ListenerPair> */
    public function getPairs(): array
    {
        return $this->pairs;
    }

    /** @return list<ClosurePairRecord> */
    public function getClosurePairs(): array
    {
        return $this->closurePairs;
    }
}
