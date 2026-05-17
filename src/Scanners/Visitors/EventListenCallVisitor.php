<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Visitors;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * Collects (event, listener) pairs from Event::listen(...) static calls.
 *
 * Only matches the `Illuminate\Support\Facades\Event` facade form. Container
 * forms (`$this->app['events']->listen(...)`) are a documented v0.1 gap.
 */
final class EventListenCallVisitor extends NodeVisitorAbstract
{
    private const EVENT_FACADE = 'Illuminate\\Support\\Facades\\Event';

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

        return null;
    }

    public function leaveNode(Node $node): null
    {
        // Read on leaveNode so NameResolver has rewritten the Name nodes inside
        // each argument's ClassConstFetch expressions to their FQCNs.
        if (! $node instanceof Node\Expr\StaticCall) {
            return null;
        }

        if (! $node->class instanceof Node\Name) {
            return null;
        }
        if ($node->class->toString() !== self::EVENT_FACADE) {
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
            $this->closurePairs[] = [
                'event' => $event,
                'line' => $second->value->getStartLine(),
                'registration' => 'event_listen_call',
            ];

            return null;
        }

        // Class-only listeners under string events (e.g. 'eloquent.*') belong
        // to ObserverScanner territory; only class-keyed events emit into
        // listeners[].
        if (! $first->value instanceof Node\Expr\ClassConstFetch) {
            return null;
        }

        $resolved = $this->listenerFromValue($second->value);
        if ($resolved === null) {
            return null;
        }

        $this->pairs[] = [
            'event' => $event,
            'listener' => $resolved['listener'],
            'method' => $resolved['method'],
        ];

        return null;
    }

    private function eventFromValue(Node\Expr $expr): ?string
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
