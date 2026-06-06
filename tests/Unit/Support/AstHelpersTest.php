<?php

declare(strict_types=1);

use Lucasp\Loom\Support\AstHelpers;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/**
 * Parse a PHP expression string and return its root expression node.
 */
function parseExpr(string $expr): Node
{
    $parser = (new ParserFactory)->createForNewestSupportedVersion();
    $ast = $parser->parse('<?php '.$expr.';');

    expect($ast)->not->toBeNull();

    $stmt = $ast[0];
    expect($stmt)->toBeInstanceOf(Node\Stmt\Expression::class);

    return $stmt->expr;
}

/**
 * Parse a `<receiver>->listen(...)` snippet (after NameResolver) and return the
 * receiver expression, so FQCN-gated shapes resolve `use` imports.
 */
function parseListenReceiver(string $useLines, string $body, bool $namespaced = true): Node\Expr
{
    $parser = (new ParserFactory)->createForNewestSupportedVersion();
    $prefix = $namespaced ? "<?php namespace App;\n" : "<?php\n";
    $ast = $parser->parse($prefix.$useLines."\n".$body.';');

    expect($ast)->not->toBeNull();

    $collector = new class extends PhpParser\NodeVisitorAbstract
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

    $traverser = new NodeTraverser;
    $traverser->addVisitor(new NameResolver);
    $traverser->addVisitor($collector);
    $traverser->traverse($ast);

    expect($collector->receiver)->not->toBeNull();

    return $collector->receiver;
}

it('resolves a bare new expression', function () {
    expect(AstHelpers::resolveStaticClass(parseExpr('new Foo')))->toBe('Foo');
});

it('resolves a ::class fetch', function () {
    expect(AstHelpers::resolveStaticClass(parseExpr('Foo::class')))->toBe('Foo');
});

it('unwraps a single fluent method call to the new target', function () {
    expect(AstHelpers::resolveStaticClass(parseExpr("(new Foo)->locale('es')")))->toBe('Foo');
});

it('unwraps a multi-link fluent chain to the new target', function () {
    expect(AstHelpers::resolveStaticClass(parseExpr("(new Foo)->locale('es')->onQueue('q')")))->toBe('Foo');
});

it('returns null for a static-call receiver chain', function () {
    expect(AstHelpers::resolveStaticClass(parseExpr('Foo::bar()->baz()')))->toBeNull();
});

it('returns null for a variable receiver chain', function () {
    expect(AstHelpers::resolveStaticClass(parseExpr("\$instance->locale('es')")))->toBeNull();
});

it('returns null for a bare variable', function () {
    expect(AstHelpers::resolveStaticClass(parseExpr('$x')))->toBeNull();
});

// -----------------------------------------------------------------------------
// channelList() — notification channel filters
// -----------------------------------------------------------------------------

it('resolves a plain string-literal channel array', function () {
    expect(AstHelpers::channelList(parseExpr("['mail', 'database']")))
        ->toBe(['mail', 'database']);
});

it('lowercases string-literal channel names', function () {
    expect(AstHelpers::channelList(parseExpr("['MAIL']")))
        ->toBe(['mail']);
});

it('resolves a mixed string + Class::class channel array to FQCN', function () {
    expect(AstHelpers::channelList(parseExpr("['mail', App\\Channels\\SomeChannel::class]")))
        ->toBe(['mail', 'App\\Channels\\SomeChannel']);
});

it('resolves a Class::class-only channel array to FQCN', function () {
    expect(AstHelpers::channelList(parseExpr('[App\\Channels\\SomeChannel::class]')))
        ->toBe(['App\\Channels\\SomeChannel']);
});

it('returns null for a non-array node', function () {
    expect(AstHelpers::channelList(parseExpr("'mail'")))->toBeNull();
    expect(AstHelpers::channelList(parseExpr('$channels')))->toBeNull();
});

it('returns null for a keyed channel array', function () {
    expect(AstHelpers::channelList(parseExpr("['mail' => true]")))->toBeNull();
});

it('returns null when any channel item is non-literal', function () {
    expect(AstHelpers::channelList(parseExpr("['mail', \$dynamic]")))->toBeNull();
    expect(AstHelpers::channelList(parseExpr("['mail', resolve('channel')]")))->toBeNull();
});

it('returns an empty list for an empty channel array literal', function () {
    expect(AstHelpers::channelList(parseExpr('[]')))->toBe([]);
});

// -----------------------------------------------------------------------------
// callableListener() — callable-shaped regular listeners
// -----------------------------------------------------------------------------

it('resolves Closure::fromCallable([Foo::class, \'method\']) to a listener pair', function () {
    expect(AstHelpers::callableListener(parseExpr("Closure::fromCallable([App\\Listeners\\Foo::class, 'onPlaced'])")))
        ->toBe(['listener' => 'App\\Listeners\\Foo', 'method' => 'onPlaced']);
});

it('resolves a leading-backslash \\Closure::fromCallable([Foo::class, \'method\']) the same way', function () {
    expect(AstHelpers::callableListener(parseExpr("\\Closure::fromCallable([App\\Listeners\\Foo::class, 'onPlaced'])")))
        ->toBe(['listener' => 'App\\Listeners\\Foo', 'method' => 'onPlaced']);
});

it('defaults the method to handle for single-element Closure::fromCallable([Foo::class])', function () {
    expect(AstHelpers::callableListener(parseExpr('Closure::fromCallable([App\\Listeners\\Foo::class])')))
        ->toBe(['listener' => 'App\\Listeners\\Foo', 'method' => 'handle']);
});

it('resolves a Foo::method(...) first-class callable to a listener pair', function () {
    expect(AstHelpers::callableListener(parseExpr('App\\Listeners\\Foo::onPlaced(...)')))
        ->toBe(['listener' => 'App\\Listeners\\Foo', 'method' => 'onPlaced']);
});

it('returns null for Closure::fromCallable($var) with a variable argument', function () {
    expect(AstHelpers::callableListener(parseExpr('Closure::fromCallable($var)')))->toBeNull();
});

it('returns null for a string callable like \'Foo::method\'', function () {
    expect(AstHelpers::callableListener(parseExpr("'App\\Listeners\\Foo::onPlaced'")))->toBeNull();
});

it('returns null for an instance first-class callable $obj->method(...)', function () {
    expect(AstHelpers::callableListener(parseExpr('$obj->onPlaced(...)')))->toBeNull();
});

it('returns null for a bare ::class value (handled by the caller, not callableListener)', function () {
    expect(AstHelpers::callableListener(parseExpr('App\\Listeners\\Foo::class')))->toBeNull();
});

// -----------------------------------------------------------------------------
// resolvesToEventsDispatcher() — container-form listener receivers
// -----------------------------------------------------------------------------

it('matches Shape A: $this->app[\'events\']', function () {
    $receiver = parseListenReceiver('', "\$this->app['events']->listen()");

    expect(AstHelpers::resolvesToEventsDispatcher($receiver))->toBeTrue();
});

it('does not match $this->app[\'cache\'] with a different array key', function () {
    $receiver = parseListenReceiver('', "\$this->app['cache']->listen()");

    expect(AstHelpers::resolvesToEventsDispatcher($receiver))->toBeFalse();
});

it('matches Shape B: app(Dispatcher::class) with the contract FQCN', function () {
    $receiver = parseListenReceiver(
        'use Illuminate\\Contracts\\Events\\Dispatcher;',
        'app(Dispatcher::class)->listen()',
    );

    expect(AstHelpers::resolvesToEventsDispatcher($receiver))->toBeTrue();
});

it('matches Shape B: resolve(Dispatcher::class) with the concrete FQCN', function () {
    $receiver = parseListenReceiver(
        'use Illuminate\\Events\\Dispatcher;',
        'resolve(Dispatcher::class)->listen()',
    );

    expect(AstHelpers::resolvesToEventsDispatcher($receiver))->toBeTrue();
});

it('matches Shape B: $this->app->make(Dispatcher::class)', function () {
    $receiver = parseListenReceiver(
        'use Illuminate\\Contracts\\Events\\Dispatcher;',
        '$this->app->make(Dispatcher::class)->listen()',
    );

    expect(AstHelpers::resolvesToEventsDispatcher($receiver))->toBeTrue();
});

it('matches Shape B: $this->app->makeWith(Dispatcher::class)', function () {
    $receiver = parseListenReceiver(
        'use Illuminate\\Contracts\\Events\\Dispatcher;',
        '$this->app->makeWith(Dispatcher::class)->listen()',
    );

    expect(AstHelpers::resolvesToEventsDispatcher($receiver))->toBeTrue();
});

it('matches Shape B with the bare Dispatcher basename when no use resolves it', function () {
    // Global namespace, no `use` import: NameResolver leaves the bare
    // `Dispatcher` basename, which the matcher accepts as a pragmatic fallback.
    $receiver = parseListenReceiver('', 'app(Dispatcher::class)->listen()', namespaced: false);

    expect(AstHelpers::resolvesToEventsDispatcher($receiver))->toBeTrue();
});

it('does not match Shape B when the resolved ::class is an unrelated class', function () {
    $receiver = parseListenReceiver(
        'use App\\Services\\SomeService;',
        'app(SomeService::class)->listen()',
    );

    expect(AstHelpers::resolvesToEventsDispatcher($receiver))->toBeFalse();
});

it('matches Shape C only when the variable is in the supplied dispatcher map', function () {
    $receiver = parseListenReceiver('', '$dispatcher->listen()');

    expect(AstHelpers::resolvesToEventsDispatcher($receiver, ['dispatcher' => true]))->toBeTrue();
});

it('does not match Shape C with an empty dispatcher map', function () {
    $receiver = parseListenReceiver('', '$dispatcher->listen()');

    expect(AstHelpers::resolvesToEventsDispatcher($receiver, []))->toBeFalse();
});

it('does not match an unrelated ->listen() on a variable not in the map', function () {
    $receiver = parseListenReceiver('', '$socket->listen()');

    expect(AstHelpers::resolvesToEventsDispatcher($receiver, ['dispatcher' => true]))->toBeFalse();
});
