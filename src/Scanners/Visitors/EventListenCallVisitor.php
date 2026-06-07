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
 * Collects (event, listener) pairs from Event::listen(...) calls and the
 * equivalent container forms (`$this->app['events']->listen(...)`,
 * `app(Dispatcher::class)->listen(...)`, `$dispatcher->listen(...)`).
 */
final class EventListenCallVisitor extends NodeVisitorAbstract
{
    /** @var list<ListenerPair> */
    private array $pairs = [];

    /** @var list<ClosurePairRecord> */
    private array $closurePairs = [];

    /** @var array<string, true> Variables proven to hold a Dispatcher in scope. */
    private array $dispatcherVars = [];

    /**
     * @param  array<int, Node>  $nodes
     */
    public function beforeTraverse(array $nodes): ?array
    {
        $this->pairs = [];
        $this->closurePairs = [];
        $this->dispatcherVars = [];

        return null;
    }

    public function leaveNode(Node $node): null
    {
        if ($node instanceof Node\Expr\StaticCall) {
            $this->handleStaticCall($node);

            return null;
        }

        if ($node instanceof Node\Expr\MethodCall) {
            $this->handleMethodCall($node);

            return null;
        }

        if ($node instanceof Node\Expr\Assign) {
            $this->handleAssign($node);
        }

        return null;
    }

    private function handleStaticCall(Node\Expr\StaticCall $node): void
    {
        if (! $node->class instanceof Node\Name) {
            return;
        }
        if ($node->class->toString() !== Facades::EVENT->value) {
            return;
        }
        if (! $node->name instanceof Node\Identifier) {
            return;
        }
        if ($node->name->toString() !== 'listen') {
            return;
        }
        if (count($node->args) < 2) {
            return;
        }

        $this->handleListenArgs($node->args);
    }

    private function handleMethodCall(Node\Expr\MethodCall $node): void
    {
        if (! $node->name instanceof Node\Identifier) {
            return;
        }
        if ($node->name->toString() !== 'listen') {
            return;
        }
        if (count($node->args) < 2) {
            return;
        }
        if (! AstHelpers::resolvesToEventsDispatcher($node->var, $this->dispatcherVars)) {
            return;
        }

        $this->handleListenArgs($node->args);
    }

    private function handleAssign(Node\Expr\Assign $node): void
    {
        if (! $node->var instanceof Node\Expr\Variable || ! is_string($node->var->name)) {
            return;
        }

        if (AstHelpers::resolvesToEventsDispatcher($node->expr, $this->dispatcherVars)) {
            $this->dispatcherVars[$node->var->name] = true;

            return;
        }

        // Reassignment to a non-Dispatcher value invalidates the tracked binding.
        unset($this->dispatcherVars[$node->var->name]);
    }

    /**
     * Extract the (event, listener) pair from a `listen(event, listener)`
     * arg list, shared by the facade and container receiver forms.
     *
     * @param  array<int, Node\Arg|Node\VariadicPlaceholder>  $args
     */
    private function handleListenArgs(array $args): void
    {
        $first = $args[0];
        $second = $args[1];

        if (! $first instanceof Node\Arg || ! $second instanceof Node\Arg) {
            return;
        }

        // Array first arg: one listener bound to several class-string events.
        if ($first->value instanceof Node\Expr\Array_) {
            foreach (AstHelpers::classConstList($first->value) as $event) {
                $this->recordListen($event, $second->value);
            }

            return;
        }

        $event = $this->eventFromValue($first->value);
        if ($event === null) {
            return;
        }

        if ($second->value instanceof Node\Expr\Closure
            || $second->value instanceof Node\Expr\ArrowFunction
        ) {
            $this->recordClosurePair($event, $second->value);

            return;
        }

        // String events (e.g. 'eloquent.*') belong to ObserverScanner.
        if (! $first->value instanceof Node\Expr\ClassConstFetch) {
            return;
        }

        $resolved = $this->listenerFromValue($second->value);
        if ($resolved === null) {
            return;
        }

        $this->pairs[] = new ListenerPair(
            event: $event,
            listener: $resolved['listener'],
            method: $resolved['method'],
        );
    }

    private function recordListen(string $event, Node\Expr $listener): void
    {
        if ($listener instanceof Node\Expr\Closure || $listener instanceof Node\Expr\ArrowFunction) {
            $this->recordClosurePair($event, $listener);

            return;
        }

        $resolved = $this->listenerFromValue($listener);
        if ($resolved === null) {
            return;
        }

        $this->pairs[] = new ListenerPair(
            event: $event,
            listener: $resolved['listener'],
            method: $resolved['method'],
        );
    }

    private function recordClosurePair(string $event, Node\Expr\Closure|Node\Expr\ArrowFunction $closure): void
    {
        $this->closurePairs[] = new ClosurePairRecord(
            event: $event,
            line: $closure->getStartLine(),
            endLine: $closure->getEndLine(),
            registration: ListenerRegistration::EVENT_LISTEN_CALL,
        );
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

            return null;
        }

        // Closure::fromCallable([...]) and Foo::method(...) first-class callables.
        return AstHelpers::callableListener($value);
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
