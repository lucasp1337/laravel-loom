<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Visitors;

use Lucasp\Loom\Dto\ClosurePairRecord;
use Lucasp\Loom\Dto\ListenerPair;
use Lucasp\Loom\Index\ListenerRegistration;
use Lucasp\Loom\Support\AstHelpers;
use Lucasp\Loom\Support\Facades;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * Collects (event, listener) pairs from Event::listen(...) calls.
 * Container forms (`$this->app['events']->listen(...)`) are not matched.
 */
final class EventListenCallVisitor extends NodeVisitorAbstract
{
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

        return null;
    }

    public function leaveNode(Node $node): null
    {
        if (! $node instanceof Node\Expr\StaticCall) {
            return null;
        }

        if (! $node->class instanceof Node\Name) {
            return null;
        }
        if ($node->class->toString() !== Facades::EVENT->value) {
            return null;
        }
        if (! $node->name instanceof Node\Identifier) {
            return null;
        }
        if ($node->name->toString() !== 'listen') {
            return null;
        }
        if (count($node->args) < 2) {
            return null;
        }

        $first = $node->args[0];
        $second = $node->args[1];

        if (! $first instanceof Node\Arg || ! $second instanceof Node\Arg) {
            return null;
        }

        $event = $this->eventFromValue($first->value);
        if ($event === null) {
            return null;
        }

        if ($second->value instanceof Node\Expr\Closure
            || $second->value instanceof Node\Expr\ArrowFunction
        ) {
            $this->closurePairs[] = new ClosurePairRecord(
                event: $event,
                line: $second->value->getStartLine(),
                endLine: $second->value->getEndLine(),
                registration: ListenerRegistration::EVENT_LISTEN_CALL,
            );

            return null;
        }

        // String events (e.g. 'eloquent.*') belong to ObserverScanner.
        if (! $first->value instanceof Node\Expr\ClassConstFetch) {
            return null;
        }

        $resolved = $this->listenerFromValue($second->value);
        if ($resolved === null) {
            return null;
        }

        $this->pairs[] = new ListenerPair(
            event: $event,
            listener: $resolved['listener'],
            method: $resolved['method'],
        );

        return null;
    }

    private function eventFromValue(Node\Expr $expr): ?string
    {
        $direct = AstHelpers::classConstFqcn($expr);
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
        $direct = AstHelpers::classConstFqcn($value);
        if ($direct !== null) {
            return ['listener' => $direct, 'method' => 'handle'];
        }

        if ($value instanceof Node\Expr\Array_ && count($value->items) >= 2) {
            $listener = AstHelpers::classConstFqcn($value->items[0]->value);
            if ($listener === null) {
                return null;
            }
            $methodNode = $value->items[1]->value;
            if (! $methodNode instanceof Node\Scalar\String_) {
                return null;
            }

            return ['listener' => $listener, 'method' => $methodNode->value];
        }

        if ($value instanceof Node\Expr\Array_ && $value->items !== []) {
            $listener = AstHelpers::classConstFqcn($value->items[0]->value);
            if ($listener !== null) {
                return ['listener' => $listener, 'method' => 'handle'];
            }
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
