<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support\Ast;

use PhpParser\Node;

/**
 * A method call, static call, function call or `new` expression, read through
 * one shape. Nullsafe calls (`$x?->m()`) are a different php-parser node and
 * are not call sites.
 *
 * @internal
 */
final readonly class CallSite
{
    private function __construct(
        private Node\Expr\MethodCall|Node\Expr\StaticCall|Node\Expr\FuncCall|Node\Expr\New_ $node,
    ) {}

    /** Wrap $node, or null when it is not one of the four call shapes. */
    public static function of(Node $node): ?self
    {
        if ($node instanceof Node\Expr\MethodCall
            || $node instanceof Node\Expr\StaticCall
            || $node instanceof Node\Expr\FuncCall
            || $node instanceof Node\Expr\New_
        ) {
            return new self($node);
        }

        return null;
    }

    public function node(): Node\Expr\MethodCall|Node\Expr\StaticCall|Node\Expr\FuncCall|Node\Expr\New_
    {
        return $this->node;
    }

    public function kind(): CallKind
    {
        return match (true) {
            // `$x->m()`
            $this->node instanceof Node\Expr\MethodCall => CallKind::METHOD,
            // `X::m()`
            $this->node instanceof Node\Expr\StaticCall => CallKind::STATIC,
            // `f()`
            $this->node instanceof Node\Expr\FuncCall => CallKind::FUNCTION,
            // `new X()`
            default => CallKind::INSTANTIATION,
        };
    }

    /**
     * The method or function name as written, or the class name for `new`.
     * Null when it is dynamic (`$x->$m()`, `$f()`, `new $class`).
     */
    public function name(): ?string
    {
        $name = match (true) {
            $this->node instanceof Node\Expr\New_ => $this->node->class,
            default => $this->node->name,
        };

        return $name instanceof Node\Identifier || $name instanceof Node\Name ? $name->toString() : null;
    }

    /**
     * The receiver expression of a method call, the class of a static call or
     * `new`. Null for a function call and for an anonymous class.
     */
    public function receiver(): Node\Expr|Node\Name|null
    {
        return match (true) {
            // `$var->m()`: the object expression
            $this->node instanceof Node\Expr\MethodCall => $this->node->var,
            // `X::m()`: the class name (or a dynamic class expression)
            $this->node instanceof Node\Expr\StaticCall => $this->node->class,
            // `new X`: the class; an anonymous class has no receiver
            $this->node instanceof Node\Expr\New_ => $this->node->class instanceof Node\Stmt\Class_ ? null : $this->node->class,
            // `f()`: nothing precedes the name
            default => null,
        };
    }

    public function args(): Args
    {
        return Args::of($this->node->args);
    }

    public function line(): int
    {
        return $this->node->getStartLine();
    }

    public function isFirstClassCallable(): bool
    {
        return $this->args()->isFirstClassCallable();
    }
}
