<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support\Ast;

use PhpParser\Node;

/**
 * The class a method call's receiver holds, when the source names it:
 *
 *     self::m(), static::m(), parent::m(), X::m()     the named class
 *     $this->m()                                      the enclosing class or trait
 *     (new X)->m()                                    X
 *     app(X::class)->m(), resolve(X::class)->m()      X
 *     $container->make(X::class)->m()                 X
 *     $this->service->m()                             the declared type of the property
 *     $service->m()                                   the declared type of the parameter,
 *                                                     or one of the above assigned once
 *
 * Anything else (a union or untyped declaration, a call result, a variable
 * assigned twice) gives null.
 *
 * @internal
 */
final class ReceiverClass
{
    /** How many single assignments a variable is followed through. */
    private const MAX_ASSIGNMENT_HOPS = 3;

    /**
     * @param  Node\Stmt\ClassLike|null  $class  the enclosing class or trait
     * @param  Node\FunctionLike|null  $function  the function body the call sits in
     * @param  \Closure(string): ?string  $resolveName  resolves `self` / `static` / `parent` against the enclosing class; other names pass through
     */
    public static function of(Node\Expr|Node\Name $receiver, ?Node\Stmt\ClassLike $class, ?Node\FunctionLike $function, \Closure $resolveName, int $hops = 0): ?string
    {
        return match (true) {
            // `X::m()`, `self::m()`
            $receiver instanceof Node\Name => $resolveName($receiver->toString()),
            // `$this->m()`
            $receiver instanceof Node\Expr\Variable && $receiver->name === 'this' => $class?->namespacedName?->toString(),
            // `$service->m()`
            $receiver instanceof Node\Expr\Variable && is_string($receiver->name) => self::ofVariable($receiver->name, $class, $function, $resolveName, $hops),
            // `(new X)->m()`
            $receiver instanceof Node\Expr\New_ => $receiver->class instanceof Node\Name ? $resolveName($receiver->class->toString()) : null,
            // `app(X::class)->m()`, `resolve(X::class)->m()`
            $receiver instanceof Node\Expr\FuncCall
                && $receiver->name instanceof Node\Name
                && collect(['app', 'resolve'])->containsStrict(strtolower($receiver->name->toString())) => self::firstClassArgument($receiver, $resolveName),
            // `$container->make(X::class)->m()`
            $receiver instanceof Node\Expr\MethodCall
                && $receiver->name instanceof Node\Identifier
                && collect(['make', 'makeWith'])->containsStrict($receiver->name->toString()) => self::firstClassArgument($receiver, $resolveName),
            // `$this->service->m()`
            $receiver instanceof Node\Expr\PropertyFetch
                && $receiver->var instanceof Node\Expr\Variable
                && $receiver->var->name === 'this'
                && $receiver->name instanceof Node\Identifier => self::ofProperty($receiver->name->toString(), $class, $resolveName),
            default => null,
        };
    }

    /**
     * @param  \Closure(string): ?string  $resolveName
     */
    private static function ofVariable(string $name, ?Node\Stmt\ClassLike $class, ?Node\FunctionLike $function, \Closure $resolveName, int $hops): ?string
    {
        if ($function === null) {
            return null;
        }

        foreach ($function->getParams() as $param) {
            if ($param->var instanceof Node\Expr\Variable && $param->var->name === $name) {
                return LocalAssignments::of($function)->writes($name) ? null : self::ofType($param->type, $resolveName);
            }
        }

        $value = $hops < self::MAX_ASSIGNMENT_HOPS ? LocalAssignments::of($function)->singleValue($name) : null;

        return $value !== null ? self::of($value, $class, $function, $resolveName, $hops + 1) : null;
    }

    /**
     * A declared property or promoted constructor parameter.
     *
     * @param  \Closure(string): ?string  $resolveName
     */
    private static function ofProperty(string $name, ?Node\Stmt\ClassLike $class, \Closure $resolveName): ?string
    {
        if ($class === null) {
            return null;
        }

        foreach ($class->getProperties() as $property) {
            foreach ($property->props as $prop) {
                if ($prop->name->toString() === $name) {
                    return self::ofType($property->type, $resolveName);
                }
            }
        }

        foreach ($class->getMethod('__construct')?->getParams() ?? [] as $param) {
            if ($param->isPromoted() && $param->var instanceof Node\Expr\Variable && $param->var->name === $name) {
                return self::ofType($param->type, $resolveName);
            }
        }

        return null;
    }

    /**
     * `X` or `?X`; a union, an intersection or a built-in type is not one class.
     *
     * @param  \Closure(string): ?string  $resolveName
     */
    private static function ofType(?Node $type, \Closure $resolveName): ?string
    {
        $type = $type instanceof Node\NullableType ? $type->type : $type;

        return $type instanceof Node\Name ? $resolveName($type->toString()) : null;
    }

    /**
     * @param  \Closure(string): ?string  $resolveName
     */
    private static function firstClassArgument(Node\Expr\FuncCall|Node\Expr\MethodCall $call, \Closure $resolveName): ?string
    {
        $class = ClassRef::fromClassConstant(Args::of($call->args)->valueAt(0));

        return $class !== null ? $resolveName($class) : null;
    }
}
