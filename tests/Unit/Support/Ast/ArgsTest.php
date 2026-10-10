<?php

declare(strict_types=1);

use Lucasp\Loom\Support\Ast\Args;
use PhpParser\Node;

/** The argument list of a parsed call expression. */
function argsOf(string $call): Args
{
    $node = parseExpr($call);
    assert($node instanceof Node\Expr\CallLike);

    return Args::of($node->args);
}

it('reads positional arguments by index', function () {
    $args = argsOf("f('a', 2, \$c)");

    expect($args->count())->toBe(3)
        ->and($args->at(0)?->value)->toBeInstanceOf(Node\Scalar\String_::class)
        ->and($args->at(1)?->position)->toBe(1)
        ->and($args->at(3))->toBeNull()
        ->and($args->valueAt(2))->toBeInstanceOf(Node\Expr\Variable::class)
        ->and($args->valueAt(5))->toBeNull();
});

it('keeps positional lookup independent of argument names', function () {
    $args = argsOf("f(b: 'x', a: 'y')");

    expect($args->at(0)?->name)->toBe('b')
        ->and($args->valueAt(1))->toBeInstanceOf(Node\Scalar\String_::class);
});

it('finds a named argument', function () {
    $args = argsOf("f('p', api: 'v1', web: 'w')");

    expect($args->named('web')?->position)->toBe(2)
        ->and($args->named('api')?->name)->toBe('api')
        ->and($args->named('missing'))->toBeNull();
});

it('looks a parameter up positionally or by name', function () {
    // positional wins when the argument at that position is unnamed
    $positional = argsOf("f('x', 'y')");
    expect($positional->lookup(1, 'second')?->position)->toBe(1);

    // a named argument is used when the position holds a different named one
    $named = argsOf("f(first: 'x', second: 'y')");
    expect($named->lookup(1, 'second')?->position)->toBe(1)
        ->and($named->lookup(0, 'second')?->position)->toBe(1)
        ->and($named->lookup(0, 'third'))->toBeNull();
});

it('flags spread arguments and keeps them readable', function () {
    $args = argsOf('f($a, ...$rest)');

    expect($args->hasUnpack())->toBeTrue()
        ->and($args->at(1)?->unpacked)->toBeTrue()
        ->and($args->at(0)?->unpacked)->toBeFalse()
        ->and(argsOf('f($a)')->hasUnpack())->toBeFalse();
});

it('detects first-class callable syntax', function () {
    $callable = argsOf('strlen(...)');

    expect($callable->isFirstClassCallable())->toBeTrue()
        ->and($callable->count())->toBe(1)
        ->and($callable->at(0))->toBeNull()
        ->and($callable->all())->toBe([])
        ->and($callable->values())->toBe([])
        ->and(argsOf('strlen($s)')->isFirstClassCallable())->toBeFalse();
});

it('lists every real argument and its value', function () {
    $args = argsOf("f('a', x: 1)");

    expect($args->all())->toHaveCount(2)
        ->and($args->values())->toHaveCount(2)
        ->and($args->isEmpty())->toBeFalse()
        ->and(argsOf('f()')->isEmpty())->toBeTrue();
});

it('skips leading arguments and re-indexes', function () {
    $args = argsOf("broadcast_if(\$cond, 'event')")->skip(1);

    expect($args->count())->toBe(1)
        ->and($args->at(0)?->value)->toBeInstanceOf(Node\Scalar\String_::class)
        ->and($args->at(0)?->position)->toBe(0);
});
