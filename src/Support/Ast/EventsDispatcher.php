<?php

declare(strict_types=1);

namespace Lucasp\Loom\Support\Ast;

use PhpParser\Node;

/**
 * Recognises expressions that hold the events Dispatcher.
 *
 * @internal
 */
final class EventsDispatcher
{
    private const DISPATCHER_FQCNS = [
        'Illuminate\\Contracts\\Events\\Dispatcher',
        'Illuminate\\Events\\Dispatcher',
    ];

    /**
     * Whether a MethodCall's receiver resolves to the events Dispatcher.
     *
     * @param  array<string, true>  $localDispatcherVars  variable names proven to hold a Dispatcher in the current scope
     */
    public static function isReceiver(Node\Expr $receiver, array $localDispatcherVars = []): bool
    {
        // Shape A: $this->app['events'].
        if (self::isAppEventsArrayFetch($receiver)) {
            return true;
        }

        // Shape B: container resolution gated on a Dispatcher::class argument.
        if (self::isDispatcherContainerResolution($receiver)) {
            return true;
        }

        // Shape C: a variable proven to hold a Dispatcher in this scope.
        if ($receiver instanceof Node\Expr\Variable && is_string($receiver->name)) {
            return isset($localDispatcherVars[$receiver->name]);
        }

        return false;
    }

    /** Match `$this->app['events']`. */
    private static function isAppEventsArrayFetch(Node\Expr $receiver): bool
    {
        if (! $receiver instanceof Node\Expr\ArrayDimFetch) {
            return false;
        }
        if (! $receiver->dim instanceof Node\Scalar\String_ || $receiver->dim->value !== 'events') {
            return false;
        }

        $var = $receiver->var;
        if (! $var instanceof Node\Expr\PropertyFetch) {
            return false;
        }
        if (! $var->name instanceof Node\Identifier || $var->name->toString() !== 'app') {
            return false;
        }

        return $var->var instanceof Node\Expr\Variable && $var->var->name === 'this';
    }

    /**
     * Match `app(Dispatcher::class)`, `resolve(Dispatcher::class)`,
     * `$x->make(Dispatcher::class)`, `$x->makeWith(Dispatcher::class)`.
     */
    private static function isDispatcherContainerResolution(Node\Expr $receiver): bool
    {
        if ($receiver instanceof Node\Expr\FuncCall) {
            if (! $receiver->name instanceof Node\Name) {
                return false;
            }
            $fn = $receiver->name->toString();
            if ($fn !== 'app' && $fn !== 'resolve') {
                return false;
            }

            return self::firstArgIsDispatcherClass(Args::of($receiver->args));
        }

        if ($receiver instanceof Node\Expr\MethodCall) {
            if (! $receiver->name instanceof Node\Identifier) {
                return false;
            }
            $method = $receiver->name->toString();
            if ($method !== 'make' && $method !== 'makeWith') {
                return false;
            }

            return self::firstArgIsDispatcherClass(Args::of($receiver->args));
        }

        return false;
    }

    private static function firstArgIsDispatcherClass(Args $args): bool
    {
        $fqcn = ClassRef::fromClassConstant($args->valueAt(0));
        if ($fqcn === null) {
            return false;
        }

        return self::matchesDispatcher($fqcn);
    }

    /** Accept a full Dispatcher FQCN or the bare `Dispatcher` basename. */
    private static function matchesDispatcher(string $className): bool
    {
        if (in_array($className, self::DISPATCHER_FQCNS, true)) {
            return true;
        }

        return $className === 'Dispatcher';
    }
}
