<?php

declare(strict_types=1);

use Lucasp\Loom\Support\Ast\CallChain;
use PhpParser\Node\Expr\Variable;

/** @return list<string> method names of a parsed chain, root first */
function chainNames(string $expr): array
{
    $chain = CallChain::from(parseExpr($expr));
    expect($chain)->not->toBeNull();

    return array_map(fn ($link) => $link->name(), $chain->links());
}

it('orders links root first for a static-rooted chain', function () {
    expect(chainNames("Route::get('/x', \$a)->name('x')->middleware('auth')"))
        ->toBe(['get', 'name', 'middleware']);
});

it('exposes the root, its receiver and the modifiers', function () {
    $chain = CallChain::from(parseExpr("Schedule::command('x')->daily()->at('1:00')"));

    expect($chain?->root()->name())->toBe('command')
        ->and($chain?->root()->receiver()?->toString())->toBe('Schedule')
        ->and($chain?->root()->args()->count())->toBe(1)
        ->and($chain?->modifiers())->toHaveCount(2)
        ->and($chain?->withoutLast())->toHaveCount(2)
        ->and($chain?->links()[2]->args()->count())->toBe(1);
});

it('roots a method chain at a non-call receiver', function () {
    $chain = CallChain::from(parseExpr('$schedule->call($f)->hourly()'));

    expect($chain?->root()->name())->toBe('call')
        ->and($chain?->root()->receiver())->toBeInstanceOf(Variable::class)
        ->and(chainNames('$schedule->call($f)->hourly()'))->toBe(['call', 'hourly']);
});

it('stops at a function-call receiver', function () {
    expect(chainNames('app()->make(X::class)->run()'))->toBe(['make', 'run']);
});

it('returns a single link for one call', function () {
    $chain = CallChain::from(parseExpr('Route::get()'));

    expect($chain?->links())->toHaveCount(1)
        ->and($chain?->modifiers())->toBe([])
        ->and($chain?->withoutLast())->toBe([]);
});

it('returns null when a method name is dynamic', function () {
    expect(CallChain::from(parseExpr("Route::get()->\$m('x')")))->toBeNull()
        ->and(CallChain::from(parseExpr("Route::get()->name('x')->\$m()")))->toBeNull();
});

it('returns null when the static class is dynamic', function () {
    expect(CallChain::from(parseExpr('$class::get()->name()')))->toBeNull();
});

it('returns null for an expression that is not a call', function () {
    expect(CallChain::from(parseExpr('$x')))->toBeNull()
        ->and(CallChain::from(parseExpr('new Foo')))->toBeNull();
});

it('does not read through a nullsafe call', function () {
    // `?->` is a different node, so the chain ends there (and is not a chain at all when outermost)
    expect(CallChain::from(parseExpr('$x?->a()->b()')))->not->toBeNull()
        ->and(chainNames('$x?->a()->b()'))->toBe(['b'])
        ->and(CallChain::from(parseExpr('$x->a()?->b()')))->toBeNull();
});

it('reports each link at the start line of its whole expression', function () {
    $chain = CallChain::from(parseExpr("\n\nRoute::get('/x')\n    ->name('x')"));

    expect(array_map(fn ($l) => $l->line(), $chain?->links() ?? []))->toBe([3, 3]);
});
