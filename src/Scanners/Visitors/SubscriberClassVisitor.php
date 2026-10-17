<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Visitors;

use Illuminate\Support\Str;
use Lucasp\Loom\Dto\ClosurePairRecord;
use Lucasp\Loom\Dto\ListenerHandle;
use Lucasp\Loom\Dto\ListenerPair;
use Lucasp\Loom\Dto\SubscriberClassRecord;
use Lucasp\Loom\Index\ListenerRegistration;
use Lucasp\Loom\Index\UnresolvedReason;
use Lucasp\Loom\Support\Ast\Args;
use Lucasp\Loom\Support\Ast\ClassRef;
use Lucasp\Loom\Support\Ast\ListenerRef;
use Lucasp\Loom\Support\EloquentEventName;
use Lucasp\Loom\Support\LaravelClasses;
use PhpParser\Node;

/**
 * Extracts events handled by a class's `subscribe()` method — either via the
 * returned event=>handler map or imperative `$events->listen(...)` calls.
 * A subscriber can mix both forms.
 *
 * Return-array form: `[Event::class => 'method']` or `[Event::class => [self::class,
 * 'method']]` (`self`, `static` or the class's own name), handled by the
 * subscriber itself. A closure value becomes a closure listener.
 *
 * Imperative form: `->listen(...)` on the method's first parameter, whatever its
 * name or type. The visitor follows control flow (`if`, `foreach`, `try`) but not
 * nested closures or other methods. Routing of the second argument:
 * - own class tuple or a bare `'method'` string: the subscriber's own handles;
 * - `[Other::class, 'method']`: a regular listener entry for `Other`, whose
 *   `registration` becomes `subscriber`, the highest precedence, as the
 *   subscriber is what registers it at runtime;
 * - a `'Class@method'` string or `Class::class.'@method'`: as the tuple form;
 * - a closure or arrow function: a closure listener.
 *
 * An event or handler of any other shape is recorded as unresolved.
 *
 * @internal
 */
final class SubscriberClassVisitor extends CollectingVisitor
{
    use RecordsUnresolvedRegistrations;

    /** @var list<SubscriberClassRecord> */
    private array $classes = [];

    private ?string $currentClassFqcn = null;

    protected function reset(): void
    {
        $this->classes = [];
        $this->currentClassFqcn = null;
        $this->resetUnresolved();
    }

    public function leaveNode(Node $node): null
    {
        if (! $node instanceof Node\Stmt\Class_) {
            return null;
        }
        if ($node->namespacedName === null) {
            return null;
        }

        $subscribeMethod = null;
        foreach ($node->stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\ClassMethod && $stmt->name->toString() === 'subscribe') {
                $subscribeMethod = $stmt;
                break;
            }
        }

        if ($subscribeMethod === null) {
            return null;
        }

        $queued = ClassRef::declaresInterface($node, LaravelClasses::SHOULD_QUEUE->value);

        $this->currentClassFqcn = $node->namespacedName->toString();
        [$handles, $closureHandles, $foreignPairs] = $this->extractMethodBody($subscribeMethod);
        $this->currentClassFqcn = null;

        $this->classes[] = new SubscriberClassRecord(
            fqcn: $node->namespacedName->toString(),
            line: $node->getStartLine(),
            queued: $queued,
            handles: $handles,
            closureHandles: $closureHandles,
            foreignPairs: $foreignPairs,
        );

        return null;
    }

    /**
     * @return array{0: list<ListenerHandle>, 1: list<ClosurePairRecord>, 2: list<ListenerPair>}
     */
    private function extractMethodBody(Node\Stmt\ClassMethod $method): array
    {
        if ($method->stmts === null) {
            return [[], [], []];
        }

        $handles = [];
        $closureHandles = [];
        $foreignPairs = [];

        foreach ($method->stmts as $stmt) {
            if (! $stmt instanceof Node\Stmt\Return_) {
                continue;
            }
            if (! $stmt->expr instanceof Node\Expr\Array_) {
                continue;
            }

            foreach ($stmt->expr->items as $item) {
                if ($item->key === null) {
                    continue;
                }
                $event = ListenerRef::event($item->key);
                if ($event === null) {
                    $this->recordUnresolved($item->key, UnresolvedReason::UNREADABLE_LISTENER);

                    continue;
                }

                if ($item->value instanceof Node\Expr\Closure
                    || $item->value instanceof Node\Expr\ArrowFunction
                ) {
                    $closureHandles[] = new ClosurePairRecord(event: $event, line: $item->value->getStartLine(), endLine: $item->value->getEndLine(), registration: ListenerRegistration::SUBSCRIBER);

                    continue;
                }

                // `eloquent.{hook}: {Model}` handlers belong to ObserverScanner.
                if (EloquentEventName::is($event)) {
                    continue;
                }

                $methodName = $this->extractMethodName($item->value);
                if ($methodName === null) {
                    $this->recordUnresolved($item->value, UnresolvedReason::UNREADABLE_LISTENER);

                    continue;
                }

                $handles[] = new ListenerHandle(event: $event, method: $methodName);
            }

            break;
        }

        // Imperative form requires a first parameter (the dispatcher).
        if (count($method->params) === 0) {
            return [$handles, $closureHandles, $foreignPairs];
        }

        $firstParam = $method->params[0];
        if (! $firstParam->var instanceof Node\Expr\Variable
            || ! is_string($firstParam->var->name)
        ) {
            return [$handles, $closureHandles, $foreignPairs];
        }

        $paramName = $firstParam->var->name;

        $this->walkBody($method->stmts, $paramName, $handles, $closureHandles, $foreignPairs);

        return [$handles, $closureHandles, $foreignPairs];
    }

    /**
     * Descends into control-flow but not into closures or nested functions.
     *
     * @param  array<int, Node\Stmt>  $stmts
     * @param  list<ListenerHandle>  $handles
     * @param  list<ClosurePairRecord>  $closureHandles
     * @param  list<ListenerPair>  $foreignPairs
     */
    private function walkBody(array $stmts, string $paramName, array &$handles, array &$closureHandles, array &$foreignPairs): void
    {
        foreach ($stmts as $stmt) {
            $this->walkStmt($stmt, $paramName, $handles, $closureHandles, $foreignPairs);
        }
    }

    /**
     * @param  list<ListenerHandle>  $handles
     * @param  list<ClosurePairRecord>  $closureHandles
     * @param  list<ListenerPair>  $foreignPairs
     */
    private function walkStmt(Node\Stmt $stmt, string $paramName, array &$handles, array &$closureHandles, array &$foreignPairs): void
    {
        if ($stmt instanceof Node\Stmt\Expression) {
            $this->inspectExpr($stmt->expr, $paramName, $handles, $closureHandles, $foreignPairs);

            return;
        }

        if ($stmt instanceof Node\Stmt\If_) {
            $this->walkBody($stmt->stmts, $paramName, $handles, $closureHandles, $foreignPairs);
            foreach ($stmt->elseifs as $elseif) {
                $this->walkBody($elseif->stmts, $paramName, $handles, $closureHandles, $foreignPairs);
            }
            if ($stmt->else !== null) {
                $this->walkBody($stmt->else->stmts, $paramName, $handles, $closureHandles, $foreignPairs);
            }

            return;
        }

        if ($stmt instanceof Node\Stmt\Foreach_
            || $stmt instanceof Node\Stmt\For_
            || $stmt instanceof Node\Stmt\While_
            || $stmt instanceof Node\Stmt\Do_
        ) {
            $this->walkBody($stmt->stmts, $paramName, $handles, $closureHandles, $foreignPairs);

            return;
        }

        if ($stmt instanceof Node\Stmt\Switch_) {
            foreach ($stmt->cases as $case) {
                $this->walkBody($case->stmts, $paramName, $handles, $closureHandles, $foreignPairs);
            }

            return;
        }

        if ($stmt instanceof Node\Stmt\TryCatch) {
            $this->walkBody($stmt->stmts, $paramName, $handles, $closureHandles, $foreignPairs);
            foreach ($stmt->catches as $catch) {
                $this->walkBody($catch->stmts, $paramName, $handles, $closureHandles, $foreignPairs);
            }
            if ($stmt->finally !== null) {
                $this->walkBody($stmt->finally->stmts, $paramName, $handles, $closureHandles, $foreignPairs);
            }
        }
    }

    /**
     * @param  list<ListenerHandle>  $handles
     * @param  list<ClosurePairRecord>  $closureHandles
     * @param  list<ListenerPair>  $foreignPairs
     */
    private function inspectExpr(Node\Expr $expr, string $paramName, array &$handles, array &$closureHandles, array &$foreignPairs): void
    {
        if (! $expr instanceof Node\Expr\MethodCall) {
            return;
        }
        if (! $expr->var instanceof Node\Expr\Variable) {
            return;
        }
        if (! is_string($expr->var->name) || $expr->var->name !== $paramName) {
            return;
        }
        if (! $expr->name instanceof Node\Identifier) {
            return;
        }
        if ($expr->name->toString() !== 'listen') {
            return;
        }
        $args = Args::of($expr->args);
        if ($args->count() < 2) {
            return;
        }

        $eventValue = $args->valueAt(0);
        $listenerValue = $args->valueAt(1);

        if ($eventValue === null || $listenerValue === null) {
            return;
        }

        $event = ListenerRef::event($eventValue);
        if ($event === null) {
            $this->recordUnresolved($eventValue, UnresolvedReason::UNREADABLE_LISTENER);

            return;
        }

        if ($listenerValue instanceof Node\Expr\Closure
            || $listenerValue instanceof Node\Expr\ArrowFunction
        ) {
            $closureHandles[] = new ClosurePairRecord(event: $event, line: $listenerValue->getStartLine(), endLine: $listenerValue->getEndLine(), registration: ListenerRegistration::SUBSCRIBER);

            return;
        }

        // `eloquent.{hook}: {Model}` handlers belong to ObserverScanner.
        if (EloquentEventName::is($event)) {
            return;
        }

        // [Class::class, 'method'] tuple.
        if ($listenerValue instanceof Node\Expr\Array_) {
            if (count($listenerValue->items) < 2) {
                $this->recordUnresolved($listenerValue, UnresolvedReason::UNREADABLE_LISTENER);

                return;
            }
            $classItem = $listenerValue->items[0];
            $methodItem = $listenerValue->items[1];

            $classFqcn = ClassRef::fromClassConstant($classItem->value);
            if ($classFqcn === null || ! $methodItem->value instanceof Node\Scalar\String_) {
                $this->recordUnresolved($listenerValue, UnresolvedReason::UNREADABLE_LISTENER);

                return;
            }
            $methodName = $methodItem->value->value;

            if ($this->isOwnClass($classFqcn)) {
                $handles[] = new ListenerHandle(event: $event, method: $methodName);

                return;
            }

            $foreignPairs[] = new ListenerPair(event: $event, listener: $classFqcn, method: $methodName);

            return;
        }

        $bareFqcn = ClassRef::fromClassConstant($listenerValue);
        if ($bareFqcn !== null) {
            if ($this->isOwnClass($bareFqcn)) {
                $handles[] = new ListenerHandle(event: $event, method: 'handle');

                return;
            }

            $foreignPairs[] = new ListenerPair(event: $event, listener: $bareFqcn, method: 'handle');

            return;
        }

        $folded = ListenerRef::fold($listenerValue);
        if ($folded === null) {
            $this->recordUnresolved($listenerValue, UnresolvedReason::UNREADABLE_LISTENER);

            return;
        }

        // `Class@method`: the class callable, or this subscriber's own method.
        $resolved = Str::contains($folded, '@') ? ListenerRef::fromString($folded) : null;
        if ($resolved !== null) {
            if ($this->isOwnClass($resolved['listener'])) {
                $handles[] = new ListenerHandle(event: $event, method: $resolved['method']);

                return;
            }

            $foreignPairs[] = new ListenerPair(event: $event, listener: $resolved['listener'], method: $resolved['method']);

            return;
        }

        // Bare string method: Laravel binds it to the subscriber instance.
        $handles[] = new ListenerHandle(event: $event, method: $folded);
    }

    private function isOwnClass(string $fqcn): bool
    {
        return $fqcn === 'self' || $fqcn === 'static' || $fqcn === $this->currentClassFqcn;
    }

    private function extractMethodName(Node\Expr $value): ?string
    {
        if ($value instanceof Node\Scalar\String_) {
            return $value->value;
        }

        if ($value instanceof Node\Expr\Array_ && count($value->items) >= 2) {
            $methodNode = $value->items[1]->value;
            if (! $methodNode instanceof Node\Scalar\String_) {
                return null;
            }

            // Accept self::, static::, or the subscriber's own FQCN.
            $classExpr = $value->items[0]->value;
            if ($classExpr instanceof Node\Expr\ClassConstFetch
                && $classExpr->class instanceof Node\Name
                && $classExpr->name instanceof Node\Identifier
                && $classExpr->name->toString() === 'class'
            ) {
                $name = $classExpr->class->toString();
                if ($name === 'self' || $name === 'static' || $name === $this->currentClassFqcn) {
                    return $methodNode->value;
                }
            }

            return null;
        }

        return null;
    }

    /** @return list<SubscriberClassRecord> */
    public function getClasses(): array
    {
        return $this->classes;
    }
}
