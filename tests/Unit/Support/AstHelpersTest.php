<?php

declare(strict_types=1);

use Lucasp\Loom\Support\AstHelpers;
use PhpParser\Node;
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
