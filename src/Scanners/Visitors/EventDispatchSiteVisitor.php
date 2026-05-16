<?php

declare(strict_types=1);

namespace Lucasp\Atlas\Scanners\Visitors;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * Collects statically resolvable event-class targets from dispatch expressions.
 *
 * Emits only when the target FQCN is statically resolvable. Dynamic forms
 * (variables, conditionals, string interpolation) are silently ignored —
 * DispatchScanner records those as unresolved_dispatches entries.
 */
final class EventDispatchSiteVisitor extends NodeVisitorAbstract
{
    private const EVENT_FACADE = 'Illuminate\\Support\\Facades\\Event';

    /** @var array<int, array{fqcn: string, line: int, form: 'helper'|'facade'|'dispatchable'}> */
    private array $targets = [];

    /**
     * @param  array<int, Node>  $nodes
     */
    public function beforeTraverse(array $nodes): ?array
    {
        $this->targets = [];

        return null;
    }

    public function leaveNode(Node $node): null
    {
        // Read in leaveNode so NameResolver has already resolved every child
        // Name (the inner New_->class or ClassConstFetch->class on the args).
        if ($node instanceof Node\Expr\FuncCall) {
            $this->handleFuncCall($node);

            return null;
        }

        if ($node instanceof Node\Expr\StaticCall) {
            $this->handleStaticCall($node);
        }

        return null;
    }

    private function handleFuncCall(Node\Expr\FuncCall $node): void
    {
        if (! $node->name instanceof Node\Name) {
            return;
        }

        if (strtolower($node->name->toString()) !== 'event') {
            return;
        }

        $fqcn = $this->resolveFirstArgClass($node->args);
        if ($fqcn !== null) {
            $this->targets[] = ['fqcn' => $fqcn, 'line' => $node->getStartLine(), 'form' => 'helper'];
        }
    }

    private function handleStaticCall(Node\Expr\StaticCall $node): void
    {
        if (! $node->class instanceof Node\Name) {
            return;
        }

        if (! $node->name instanceof Node\Identifier) {
            return;
        }

        if ($node->name->toString() !== 'dispatch') {
            return;
        }

        $className = $node->class->toString();

        if ($className === self::EVENT_FACADE || $className === 'Event') {
            $fqcn = $this->resolveFirstArgClass($node->args);
            if ($fqcn !== null) {
                $this->targets[] = ['fqcn' => $fqcn, 'line' => $node->getStartLine(), 'form' => 'facade'];
            }

            return;
        }

        // Dispatchable trait pattern: X::dispatch(...) — the class itself is the target.
        $this->targets[] = ['fqcn' => $className, 'line' => $node->getStartLine(), 'form' => 'dispatchable'];
    }

    /**
     * Resolve the FQCN of the class referenced in the first argument, if any.
     *
     * Handles:
     *   - new SomeClass(...)
     *   - SomeClass::class
     *
     * @param  array<int, Node\Arg|Node\VariadicPlaceholder>  $args
     */
    private function resolveFirstArgClass(array $args): ?string
    {
        if ($args === []) {
            return null;
        }

        $first = $args[0];
        if (! $first instanceof Node\Arg) {
            return null;
        }

        $value = $first->value;

        if ($value instanceof Node\Expr\New_ && $value->class instanceof Node\Name) {
            return $value->class->toString();
        }

        if ($value instanceof Node\Expr\ClassConstFetch
            && $value->class instanceof Node\Name
            && $value->name instanceof Node\Identifier
            && $value->name->toString() === 'class'
        ) {
            return $value->class->toString();
        }

        return null;
    }

    /**
     * @return array<int, array{fqcn: string, line: int, form: 'helper'|'facade'|'dispatchable'}>
     */
    public function getTargets(): array
    {
        return $this->targets;
    }
}
