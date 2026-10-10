<?php

declare(strict_types=1);

use Lucasp\Loom\Support\Ast\CallKind;
use Lucasp\Loom\Support\Ast\CallSite;
use PhpParser\Node;

it('wraps a method call', function () {
    $site = CallSite::of(parseExpr("\$mailer->send(\$m, 'x')"));

    expect($site)->not->toBeNull()
        ->and($site->kind())->toBe(CallKind::METHOD)
        ->and($site->name())->toBe('send')
        ->and($site->receiver())->toBeInstanceOf(Node\Expr\Variable::class)
        ->and($site->args()->count())->toBe(2)
        ->and($site->line())->toBe(1);
});

it('wraps a static call with the class name as receiver', function () {
    $site = CallSite::of(parseExpr('Route::get()'));

    expect($site?->kind())->toBe(CallKind::STATIC)
        ->and($site?->name())->toBe('get')
        ->and($site?->receiver())->toBeInstanceOf(Node\Name::class)
        ->and($site?->receiver()?->toString())->toBe('Route');
});

it('wraps a function call with no receiver', function () {
    $site = CallSite::of(parseExpr('event($e)'));

    expect($site?->kind())->toBe(CallKind::FUNCTION)
        ->and($site?->name())->toBe('event')
        ->and($site?->receiver())->toBeNull();
});

it('wraps new with the class as name and receiver', function () {
    $site = CallSite::of(parseExpr('new Foo(1)'));

    expect($site?->kind())->toBe(CallKind::INSTANTIATION)
        ->and($site?->name())->toBe('Foo')
        ->and($site?->receiver())->toBeInstanceOf(Node\Name::class)
        ->and($site?->args()->count())->toBe(1);
});

it('has no receiver for an anonymous class', function () {
    $site = CallSite::of(parseExpr('new class {}'));

    expect($site?->kind())->toBe(CallKind::INSTANTIATION)
        ->and($site?->name())->toBeNull()
        ->and($site?->receiver())->toBeNull();
});

it('returns a null name for dynamic callees', function () {
    expect(CallSite::of(parseExpr('$o->$m()'))?->name())->toBeNull()
        ->and(CallSite::of(parseExpr('Foo::$m()'))?->name())->toBeNull()
        ->and(CallSite::of(parseExpr('$f()'))?->name())->toBeNull()
        ->and(CallSite::of(parseExpr('new $class'))?->name())->toBeNull();
});

it('does not treat nullsafe calls or other expressions as call sites', function () {
    expect(CallSite::of(parseExpr('$o?->m()')))->toBeNull()
        ->and(CallSite::of(parseExpr('$x')))->toBeNull()
        ->and(CallSite::of(parseExpr('Foo::class')))->toBeNull();
});

it('reports first-class callable syntax', function () {
    expect(CallSite::of(parseExpr('Foo::bar(...)'))?->isFirstClassCallable())->toBeTrue()
        ->and(CallSite::of(parseExpr('Foo::bar()'))?->isFirstClassCallable())->toBeFalse();
});
