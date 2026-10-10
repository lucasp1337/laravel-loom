<?php

declare(strict_types=1);

use Lucasp\Loom\Support\Ast\Callables;
use Lucasp\Loom\Support\Ast\ClassRef;
use Lucasp\Loom\Support\Ast\EventsDispatcher;
use Lucasp\Loom\Support\Ast\ValueLists;
use Lucasp\Loom\Support\AstWalker;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * Parse a `<receiver>->listen(...)` snippet (after NameResolver) and return the
 * receiver expression, so FQCN-gated shapes resolve `use` imports.
 */
function parseListenReceiver(string $useLines, string $body, bool $namespaced = true): Node\Expr
{
    $prefix = $namespaced ? "<?php namespace App;\n" : "<?php\n";

    $collector = new class extends NodeVisitorAbstract
    {
        public ?Node\Expr $receiver = null;

        public function leaveNode(Node $node): null
        {
            if ($node instanceof Node\Expr\MethodCall
                && $node->name instanceof Node\Identifier
                && $node->name->toString() === 'listen'
            ) {
                $this->receiver = $node->var;
            }

            return null;
        }
    };

    AstWalker::walkSource($prefix.$useLines."\n".$body.';', [$collector]);

    expect($collector->receiver)->not->toBeNull();

    return $collector->receiver;
}

it('resolves a bare new expression', function () {
    expect(ClassRef::fromInstanceOrConstant(parseExpr('new Foo')))->toBe('Foo');
});

it('resolves a ::class fetch', function () {
    expect(ClassRef::fromInstanceOrConstant(parseExpr('Foo::class')))->toBe('Foo');
});

it('unwraps a single fluent method call to the new target', function () {
    expect(ClassRef::fromInstanceOrConstant(parseExpr("(new Foo)->locale('es')")))->toBe('Foo');
});

it('unwraps a multi-link fluent chain to the new target', function () {
    expect(ClassRef::fromInstanceOrConstant(parseExpr("(new Foo)->locale('es')->onQueue('q')")))->toBe('Foo');
});

it('returns null for a static-call receiver chain', function () {
    expect(ClassRef::fromInstanceOrConstant(parseExpr('Foo::bar()->baz()')))->toBeNull();
});

it('returns null for a variable receiver chain', function () {
    expect(ClassRef::fromInstanceOrConstant(parseExpr("\$instance->locale('es')")))->toBeNull();
});

it('returns null for a bare variable', function () {
    expect(ClassRef::fromInstanceOrConstant(parseExpr('$x')))->toBeNull();
});

// -----------------------------------------------------------------------------
// channelList() — notification channel filters
// -----------------------------------------------------------------------------

it('resolves a plain string-literal channel array', function () {
    expect(ValueLists::channels(parseExpr("['mail', 'database']")))
        ->toBe(['mail', 'database']);
});

it('lowercases string-literal channel names', function () {
    expect(ValueLists::channels(parseExpr("['MAIL']")))
        ->toBe(['mail']);
});

it('resolves a mixed string + Class::class channel array to FQCN', function () {
    expect(ValueLists::channels(parseExpr("['mail', App\\Channels\\SomeChannel::class]")))
        ->toBe(['mail', 'App\\Channels\\SomeChannel']);
});

it('resolves a Class::class-only channel array to FQCN', function () {
    expect(ValueLists::channels(parseExpr('[App\\Channels\\SomeChannel::class]')))
        ->toBe(['App\\Channels\\SomeChannel']);
});

it('returns null for a non-array node', function () {
    expect(ValueLists::channels(parseExpr("'mail'")))->toBeNull();
    expect(ValueLists::channels(parseExpr('$channels')))->toBeNull();
});

it('returns null for a keyed channel array', function () {
    expect(ValueLists::channels(parseExpr("['mail' => true]")))->toBeNull();
});

it('returns null when any channel item is non-literal', function () {
    expect(ValueLists::channels(parseExpr("['mail', \$dynamic]")))->toBeNull();
    expect(ValueLists::channels(parseExpr("['mail', resolve('channel')]")))->toBeNull();
});

it('returns an empty list for an empty channel array literal', function () {
    expect(ValueLists::channels(parseExpr('[]')))->toBe([]);
});

// -----------------------------------------------------------------------------
// callableListener() — callable-shaped regular listeners
// -----------------------------------------------------------------------------

it('resolves Closure::fromCallable([Foo::class, \'method\']) to a listener pair', function () {
    expect(Callables::listener(parseExpr("Closure::fromCallable([App\\Listeners\\Foo::class, 'onPlaced'])")))
        ->toBe(['listener' => 'App\\Listeners\\Foo', 'method' => 'onPlaced']);
});

it('resolves a leading-backslash \\Closure::fromCallable([Foo::class, \'method\']) the same way', function () {
    expect(Callables::listener(parseExpr("\\Closure::fromCallable([App\\Listeners\\Foo::class, 'onPlaced'])")))
        ->toBe(['listener' => 'App\\Listeners\\Foo', 'method' => 'onPlaced']);
});

it('defaults the method to handle for single-element Closure::fromCallable([Foo::class])', function () {
    expect(Callables::listener(parseExpr('Closure::fromCallable([App\\Listeners\\Foo::class])')))
        ->toBe(['listener' => 'App\\Listeners\\Foo', 'method' => 'handle']);
});

it('resolves a Foo::method(...) first-class callable to a listener pair', function () {
    expect(Callables::listener(parseExpr('App\\Listeners\\Foo::onPlaced(...)')))
        ->toBe(['listener' => 'App\\Listeners\\Foo', 'method' => 'onPlaced']);
});

it('returns null for Closure::fromCallable($var) with a variable argument', function () {
    expect(Callables::listener(parseExpr('Closure::fromCallable($var)')))->toBeNull();
});

it('returns null for a string callable like \'Foo::method\'', function () {
    expect(Callables::listener(parseExpr("'App\\Listeners\\Foo::onPlaced'")))->toBeNull();
});

it('returns null for an instance first-class callable $obj->method(...)', function () {
    expect(Callables::listener(parseExpr('$obj->onPlaced(...)')))->toBeNull();
});

it('returns null for a bare ::class value (handled by the caller, not callableListener)', function () {
    expect(Callables::listener(parseExpr('App\\Listeners\\Foo::class')))->toBeNull();
});

// -----------------------------------------------------------------------------
// resolvesToEventsDispatcher() — container-form listener receivers
// -----------------------------------------------------------------------------

it('matches Shape A: $this->app[\'events\']', function () {
    $receiver = parseListenReceiver('', "\$this->app['events']->listen()");

    expect(EventsDispatcher::isReceiver($receiver))->toBeTrue();
});

it('does not match $this->app[\'cache\'] with a different array key', function () {
    $receiver = parseListenReceiver('', "\$this->app['cache']->listen()");

    expect(EventsDispatcher::isReceiver($receiver))->toBeFalse();
});

it('matches Shape B: app(Dispatcher::class) with the contract FQCN', function () {
    $receiver = parseListenReceiver(
        'use Illuminate\\Contracts\\Events\\Dispatcher;',
        'app(Dispatcher::class)->listen()',
    );

    expect(EventsDispatcher::isReceiver($receiver))->toBeTrue();
});

it('matches Shape B: resolve(Dispatcher::class) with the concrete FQCN', function () {
    $receiver = parseListenReceiver(
        'use Illuminate\\Events\\Dispatcher;',
        'resolve(Dispatcher::class)->listen()',
    );

    expect(EventsDispatcher::isReceiver($receiver))->toBeTrue();
});

it('matches Shape B: $this->app->make(Dispatcher::class)', function () {
    $receiver = parseListenReceiver(
        'use Illuminate\\Contracts\\Events\\Dispatcher;',
        '$this->app->make(Dispatcher::class)->listen()',
    );

    expect(EventsDispatcher::isReceiver($receiver))->toBeTrue();
});

it('matches Shape B: $this->app->makeWith(Dispatcher::class)', function () {
    $receiver = parseListenReceiver(
        'use Illuminate\\Contracts\\Events\\Dispatcher;',
        '$this->app->makeWith(Dispatcher::class)->listen()',
    );

    expect(EventsDispatcher::isReceiver($receiver))->toBeTrue();
});

it('matches Shape B with the bare Dispatcher basename when no use resolves it', function () {
    // Global namespace, no `use` import: NameResolver leaves the bare
    // `Dispatcher` basename, which the matcher accepts as a pragmatic fallback.
    $receiver = parseListenReceiver('', 'app(Dispatcher::class)->listen()', namespaced: false);

    expect(EventsDispatcher::isReceiver($receiver))->toBeTrue();
});

it('does not match Shape B when the resolved ::class is an unrelated class', function () {
    $receiver = parseListenReceiver(
        'use App\\Services\\SomeService;',
        'app(SomeService::class)->listen()',
    );

    expect(EventsDispatcher::isReceiver($receiver))->toBeFalse();
});

it('matches Shape C only when the variable is in the supplied dispatcher map', function () {
    $receiver = parseListenReceiver('', '$dispatcher->listen()');

    expect(EventsDispatcher::isReceiver($receiver, ['dispatcher' => true]))->toBeTrue();
});

it('does not match Shape C with an empty dispatcher map', function () {
    $receiver = parseListenReceiver('', '$dispatcher->listen()');

    expect(EventsDispatcher::isReceiver($receiver, []))->toBeFalse();
});

it('does not match an unrelated ->listen() on a variable not in the map', function () {
    $receiver = parseListenReceiver('', '$socket->listen()');

    expect(EventsDispatcher::isReceiver($receiver, ['dispatcher' => true]))->toBeFalse();
});

// -----------------------------------------------------------------------------
// ClassRef::fromClassConstant() / listFrom() / declaresInterface()
// -----------------------------------------------------------------------------

it('resolves Foo::class and rejects other class constants and dynamic classes', function () {
    expect(ClassRef::fromClassConstant(parseExpr('App\\Foo::class')))->toBe('App\\Foo')
        ->and(ClassRef::fromClassConstant(parseExpr('App\\Foo::BAR')))->toBeNull()
        ->and(ClassRef::fromClassConstant(parseExpr('$class::class')))->toBeNull()
        ->and(ClassRef::fromClassConstant(parseExpr("'App\\\\Foo'")))->toBeNull()
        ->and(ClassRef::fromClassConstant(null))->toBeNull();
});

it('lists a single class constant or the class constants of an array, skipping the rest', function () {
    expect(ClassRef::listFrom(parseExpr('A::class')))->toBe(['A'])
        ->and(ClassRef::listFrom(parseExpr("[A::class, 'x', \$v, B::class]")))->toBe(['A', 'B'])
        ->and(ClassRef::listFrom(parseExpr('$v')))->toBe([]);
});

it('detects a directly implemented interface', function () {
    $ast = AstWalker::walkSource('<?php class A implements B, C\\D {}');
    $class = $ast[0];

    expect($class)->toBeInstanceOf(Node\Stmt\Class_::class)
        ->and(ClassRef::declaresInterface($class, 'B'))->toBeTrue()
        ->and(ClassRef::declaresInterface($class, 'C\\D'))->toBeTrue()
        ->and(ClassRef::declaresInterface($class, 'E'))->toBeFalse();
});

// -----------------------------------------------------------------------------
// Callables::tuple() and ValueLists::middleware()
// -----------------------------------------------------------------------------

it('extracts a [Class::class, method] tuple and rejects other arrays', function () {
    expect(Callables::tuple(parseExpr("[Foo::class, 'bar']")))->toBe(['class' => 'Foo', 'method' => 'bar'])
        ->and(Callables::tuple(parseExpr('[Foo::class]')))->toBeNull()
        ->and(Callables::tuple(parseExpr('[Foo::class, $m]')))->toBeNull()
        ->and(Callables::tuple(parseExpr("['Foo', 'bar']")))->toBeNull();
});

it('resolves middleware strings, class constants and arrays, skipping unreadable items', function () {
    $nodes = [
        parseExpr("'auth'"),
        parseExpr('Gate::class'),
        parseExpr("['a', B::class, 'k' => 'skipped', \$dyn]"),
        parseExpr('$variable'),
    ];

    expect(ValueLists::middleware($nodes))->toBe(['auth', 'Gate', 'a', 'B']);
});
