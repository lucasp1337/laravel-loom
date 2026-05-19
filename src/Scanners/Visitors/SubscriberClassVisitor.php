<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Visitors;

use Lucasp\Loom\Support\AstHelpers;
use Lucasp\Loom\Support\LaravelClasses;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * Extracts events handled by a class's `subscribe()` method — either via the
 * returned event=>handler map or imperative `$events->listen(...)` calls.
 */
final class SubscriberClassVisitor extends NodeVisitorAbstract
{
    /** @var array<int, array{fqcn: string, line: int, queued: bool, handles: array<int, array{event: string, method: string}>, closureHandles: array<int, array{event: string, line: int}>, foreignPairs: array<int, array{event: string, listener: string, method: string}>}> */
    private array $classes = [];

    private ?string $currentClassFqcn = null;

    /**
     * @param  array<int, Node>  $nodes
     */
    public function beforeTraverse(array $nodes): ?array
    {
        $this->classes = [];
        $this->currentClassFqcn = null;

        return null;
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

        $queued = AstHelpers::declaresInterface($node, LaravelClasses::SHOULD_QUEUE->value);

        $this->currentClassFqcn = $node->namespacedName->toString();
        [$handles, $closureHandles, $foreignPairs] = $this->extractMethodBody($subscribeMethod);
        $this->currentClassFqcn = null;

        $this->classes[] = [
            'fqcn' => $node->namespacedName->toString(),
            'line' => $node->getStartLine(),
            'queued' => $queued,
            'handles' => $handles,
            'closureHandles' => $closureHandles,
            'foreignPairs' => $foreignPairs,
        ];

        return null;
    }

    /**
     * @return array{0: array<int, array{event: string, method: string}>, 1: array<int, array{event: string, line: int}>, 2: array<int, array{event: string, listener: string, method: string}>}
     */
    private function extractMethodBody(Node\Stmt\ClassMethod $method): array
    {
        if ($method->stmts === null) {
            return [[], [], []];
        }

        $handles = [];
        $closureHandles = [];
        $foreignPairs = [];

        // Return-array form.
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
                $event = $this->eventFromKey($item->key);
                if ($event === null) {
                    continue;
                }

                if ($item->value instanceof Node\Expr\Closure
                    || $item->value instanceof Node\Expr\ArrowFunction
                ) {
                    $closureHandles[] = [
                        'event' => $event,
                        'line' => $item->value->getStartLine(),
                    ];

                    continue;
                }

                // Non-closure handlers under a string event key belong to
                // ObserverScanner territory and must not flow into handles[].
                if (! $item->key instanceof Node\Expr\ClassConstFetch) {
                    continue;
                }

                $methodName = $this->extractMethodName($item->value);
                if ($methodName === null) {
                    continue;
                }

                $handles[] = ['event' => $event, 'method' => $methodName];
            }

            break;
        }

        // Imperative form. Requires a first parameter to bind the dispatcher.
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
     * Recursively walk subscribe() body statements looking for
     * `$events->listen(event, listener)` calls. Descends into control-flow
     * statements; does NOT descend into closures, arrow functions, nested
     * methods, or nested functions.
     *
     * @param  array<int, Node\Stmt>  $stmts
     * @param  array<int, array{event: string, method: string}>  $handles
     * @param  array<int, array{event: string, line: int}>  $closureHandles
     * @param  array<int, array{event: string, listener: string, method: string}>  $foreignPairs
     */
    private function walkBody(array $stmts, string $paramName, array &$handles, array &$closureHandles, array &$foreignPairs): void
    {
        foreach ($stmts as $stmt) {
            $this->walkStmt($stmt, $paramName, $handles, $closureHandles, $foreignPairs);
        }
    }

    /**
     * @param  array<int, array{event: string, method: string}>  $handles
     * @param  array<int, array{event: string, line: int}>  $closureHandles
     * @param  array<int, array{event: string, listener: string, method: string}>  $foreignPairs
     */
    private function walkStmt(Node\Stmt $stmt, string $paramName, array &$handles, array &$closureHandles, array &$foreignPairs): void
    {
        // Top-level expression statement — inspect for $events->listen(...).
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
     * @param  array<int, array{event: string, method: string}>  $handles
     * @param  array<int, array{event: string, line: int}>  $closureHandles
     * @param  array<int, array{event: string, listener: string, method: string}>  $foreignPairs
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
        if (count($expr->args) < 2) {
            return;
        }

        $eventArg = $expr->args[0];
        $listenerArg = $expr->args[1];

        if (! $eventArg instanceof Node\Arg || ! $listenerArg instanceof Node\Arg) {
            return;
        }

        $event = $this->eventFromKey($eventArg->value);
        if ($event === null) {
            return;
        }

        $listenerValue = $listenerArg->value;

        // Closure / arrow function.
        if ($listenerValue instanceof Node\Expr\Closure
            || $listenerValue instanceof Node\Expr\ArrowFunction
        ) {
            $closureHandles[] = [
                'event' => $event,
                'line' => $listenerValue->getStartLine(),
            ];

            return;
        }

        // Tuple form: [Class::class, 'method'].
        if ($listenerValue instanceof Node\Expr\Array_) {
            if (count($listenerValue->items) < 2) {
                return;
            }
            $classItem = $listenerValue->items[0];
            $methodItem = $listenerValue->items[1];

            $classFqcn = AstHelpers::classConstFqcn($classItem->value);
            if ($classFqcn === null) {
                return;
            }
            if (! $methodItem->value instanceof Node\Scalar\String_) {
                return;
            }
            $methodName = $methodItem->value->value;

            if ($this->isOwnClass($classFqcn)) {
                $handles[] = ['event' => $event, 'method' => $methodName];

                return;
            }

            $foreignPairs[] = [
                'event' => $event,
                'listener' => $classFqcn,
                'method' => $methodName,
            ];

            return;
        }

        // Bare string method: $events->listen(Event::class, 'method') — Laravel
        // binds bare-string callables to the subscriber instance.
        if ($listenerValue instanceof Node\Scalar\String_) {
            $handles[] = ['event' => $event, 'method' => $listenerValue->value];

            return;
        }

        // Bare class-const fetch: $events->listen(Event::class, OtherClass::class).
        $bareFqcn = AstHelpers::classConstFqcn($listenerValue);
        if ($bareFqcn !== null) {
            if ($this->isOwnClass($bareFqcn)) {
                $handles[] = ['event' => $event, 'method' => 'handle'];

                return;
            }

            $foreignPairs[] = [
                'event' => $event,
                'listener' => $bareFqcn,
                'method' => 'handle',
            ];
        }
    }

    private function isOwnClass(string $fqcn): bool
    {
        return $fqcn === 'self' || $fqcn === 'static' || $fqcn === $this->currentClassFqcn;
    }

    private function eventFromKey(Node\Expr $expr): ?string
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

            // Accept self::class, static::class, or the subscriber's own FQCN.
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

    /**
     * @return array<int, array{fqcn: string, line: int, queued: bool, handles: array<int, array{event: string, method: string}>, closureHandles: array<int, array{event: string, line: int}>, foreignPairs: array<int, array{event: string, listener: string, method: string}>}>
     */
    public function getClasses(): array
    {
        return $this->classes;
    }
}
