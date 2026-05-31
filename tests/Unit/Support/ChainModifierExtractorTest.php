<?php

declare(strict_types=1);

use Lucasp\Loom\Support\ChainModifierExtractor;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/**
 * Parse an expression snippet and return its fluent `->method()` chain links in
 * source order (innermost-first), exactly as DispatchSiteVisitor hands them to
 * {@see ChainModifierExtractor::extract()}.
 *
 * The snippet must be a single expression statement whose outermost node is a
 * MethodCall chain, e.g. `(new Job)->onQueue('q')->delay(5)`.
 *
 * @return list<Node\Expr\MethodCall>
 */
function chainLinks(string $expression): array
{
    $parser = (new ParserFactory)->createForNewestSupportedVersion();
    $ast = $parser->parse("<?php {$expression};");

    expect($ast)->not->toBeNull();

    // Resolve names so `new App\Foo` etc. behave like the visitor sees them.
    $traverser = new NodeTraverser;
    $traverser->addVisitor(new NameResolver);
    $ast = $traverser->traverse($ast);

    $stmt = $ast[0];
    expect($stmt)->toBeInstanceOf(Node\Stmt\Expression::class);

    /** @var Node\Expr $expr */
    $expr = $stmt->expr;

    $links = [];
    $current = $expr;
    while ($current instanceof Node\Expr\MethodCall) {
        $links[] = $current;
        $current = $current->var;
    }

    // Walked outermost-first; reverse to source (innermost-first) order, which
    // is the order DispatchSiteVisitor::innerChainLinks() produces.
    return array_reverse($links);
}

// -----------------------------------------------------------------------------
// Empty / no-modifier
// -----------------------------------------------------------------------------

it('returns an empty overrides for an empty link list', function () {
    $overrides = ChainModifierExtractor::extract([]);

    expect($overrides->isEmpty())->toBeTrue();
    expect($overrides->toArray())->toBe([]);
});

it('returns an empty overrides when only unknown methods are present', function () {
    $overrides = ChainModifierExtractor::extract(chainLinks("(new Foo)->withFoo('x')->setBar(1)"));

    expect($overrides->isEmpty())->toBeTrue();
    expect($overrides->toArray())->toBe([]);
});

// -----------------------------------------------------------------------------
// Each recognised method -> key
// -----------------------------------------------------------------------------

it('maps ->locale(...) to the locale key', function () {
    $overrides = ChainModifierExtractor::extract(chainLinks("(new Foo)->locale('es')"));

    expect($overrides->locale)->toBe('es');
    expect($overrides->toArray())->toBe(['locale' => 'es']);
});

it('maps ->mailer(...) to the mailer key', function () {
    $overrides = ChainModifierExtractor::extract(chainLinks("(new Foo)->mailer('ses')"));

    expect($overrides->mailer)->toBe('ses');
    expect($overrides->toArray())->toBe(['mailer' => 'ses']);
});

it('maps ->onConnection(...) to the connection key', function () {
    $overrides = ChainModifierExtractor::extract(chainLinks("(new Foo)->onConnection('redis')"));

    expect($overrides->connection)->toBe('redis');
    expect($overrides->toArray())->toBe(['connection' => 'redis']);
});

it('maps ->onQueue(...) to the queue key', function () {
    $overrides = ChainModifierExtractor::extract(chainLinks("(new Foo)->onQueue('high')"));

    expect($overrides->queue)->toBe('high');
    expect($overrides->toArray())->toBe(['queue' => 'high']);
});

it('maps ->delay(<int>) to the delay key', function () {
    $overrides = ChainModifierExtractor::extract(chainLinks('(new Foo)->delay(60)'));

    expect($overrides->delay)->toBe(60);
    expect($overrides->toArray())->toBe(['delay' => 60]);
});

it('maps ->afterCommit() to after_commit:true', function () {
    $overrides = ChainModifierExtractor::extract(chainLinks('(new Foo)->afterCommit()'));

    expect($overrides->afterCommit)->toBeTrue();
    expect($overrides->toArray())->toBe(['after_commit' => true]);
});

// -----------------------------------------------------------------------------
// Integer-only delay rule
// -----------------------------------------------------------------------------

it('ignores ->delay($var) — non-literal delay yields no key', function () {
    $overrides = ChainModifierExtractor::extract(chainLinks('(new Foo)->delay($seconds)'));

    expect($overrides->delay)->toBeNull();
    expect($overrides->toArray())->toBe([]);
});

it('ignores ->delay(now()->addMinutes(5)) — expression delay yields no key', function () {
    $overrides = ChainModifierExtractor::extract(chainLinks('(new Foo)->delay(now()->addMinutes(5))'));

    expect($overrides->delay)->toBeNull();
    expect($overrides->toArray())->toBe([]);
});

it('ignores ->delay("60") — string delay yields no key', function () {
    $overrides = ChainModifierExtractor::extract(chainLinks("(new Foo)->delay('60')"));

    expect($overrides->delay)->toBeNull();
    expect($overrides->toArray())->toBe([]);
});

it('ignores ->onQueue($var) — non-literal string modifier yields no key', function () {
    $overrides = ChainModifierExtractor::extract(chainLinks('(new Foo)->onQueue($name)'));

    expect($overrides->queue)->toBeNull();
    expect($overrides->toArray())->toBe([]);
});

// -----------------------------------------------------------------------------
// Combining keys + last-literal-wins
// -----------------------------------------------------------------------------

it('captures multiple distinct keys from one chain in schema order', function () {
    $overrides = ChainModifierExtractor::extract(
        chainLinks("(new Foo)->onConnection('redis')->onQueue('high')->delay(60)->afterCommit()"),
    );

    expect($overrides->toArray())->toBe([
        'connection' => 'redis',
        'queue' => 'high',
        'delay' => 60,
        'after_commit' => true,
    ]);
});

it('emits keys in schema order regardless of source order', function () {
    $overrides = ChainModifierExtractor::extract(
        chainLinks("(new Foo)->delay(5)->onQueue('q')->locale('es')"),
    );

    // Source order is delay, queue, locale; emitted order must be schema order.
    expect(array_keys($overrides->toArray()))->toBe(['locale', 'queue', 'delay']);
});

it('lets the last literal win when the same key appears twice', function () {
    $overrides = ChainModifierExtractor::extract(
        chainLinks("(new Foo)->onQueue('low')->onQueue('high')"),
    );

    expect($overrides->queue)->toBe('high');
    expect($overrides->toArray())->toBe(['queue' => 'high']);
});

it('keeps an earlier literal when a later same-key call is non-literal', function () {
    // The non-literal ->delay($var) link is simply ignored; it does not clear a
    // previously captured literal delay.
    $overrides = ChainModifierExtractor::extract(
        chainLinks('(new Foo)->delay(60)->delay($var)'),
    );

    expect($overrides->delay)->toBe(60);
});

it('ignores unknown methods interleaved with recognised ones', function () {
    $overrides = ChainModifierExtractor::extract(
        chainLinks("(new Foo)->withFoo('x')->onQueue('high')->setBar(1)->delay(30)"),
    );

    expect($overrides->toArray())->toBe(['queue' => 'high', 'delay' => 30]);
});
